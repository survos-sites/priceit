<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\InventoryCollection;
use App\Entity\Item;
use App\Entity\User;
use App\Profile\CaptureProfile;
use App\Workflow\ItemFlow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private int $itemId;

    protected function setUp(): void
    {
        // An isolated database is mandatory: never drop a developer's inventory.
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new User())->setEmail('inventory-test@example.test')->setPassword('test')->setIsVerified(true);
        $item = new Item('inventory-test', CaptureProfile::MedicalEquipment);
        $item->marking = ItemFlow::PLACE_SUGGESTED;
        $item->setAttributes(['source' => 'inventory']);
        $item->setInventorySuggestion(['title' => 'Adjustable crutches', 'description' => 'Pair of aluminum crutches', 'equipmentType' => 'Crutches', 'category' => 'Mobility', 'confidence' => 'high']);
        $this->em->persist($user);
        $this->em->persist($item);
        $this->em->flush();
        $this->itemId = $item->getId();
        $this->browser->loginUser($user);
    }

    public function testReviewSaveAndReopenWithoutDuplicate(): void
    {
        $crawler = $this->browser->request('GET', '/inventory/items/'.$this->itemId);
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('title', 'Adjustable crutches');
        $form = $crawler->selectButton('Save to collection')->form([
            'title' => 'Adult crutches', 'description' => 'Reviewed pair', 'equipmentType' => 'Crutches', 'location' => 'Shelf B', 'collection' => 'medical',
        ]);
        $values = $form->getPhpValues();
        $this->browser->submit($form);
        self::assertResponseRedirects('/inventory/items/'.$this->itemId, 303);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('h2', 'Adult crutches');
        self::assertSelectorTextContains('.inventory-record', 'Medical loan closet');
        self::assertSelectorTextContains('.inventory-record', 'Shelf B');
        $this->browser->request('POST', '/inventory/items/'.$this->itemId.'/save', $values + ['ignored' => 'retry']);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(Item::class)->count([]));
        self::assertSame(1, $this->em->getRepository(InventoryCollection::class)->count([]));
        $saved = $this->em->find(Item::class, $this->itemId);
        self::assertSame('Adult crutches', $saved->getTitle());
        self::assertSame('Adjustable crutches', $saved->getInventorySuggestion()['title']);
        self::assertFalse($saved->printRequested);
        self::assertNull($saved->getQuickbaseExportedAt());
        $this->browser->request('GET', '/inventory');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-content', 'Adult crutches');
    }

    public function testMultiplePhotosAndRecordingsRemainOneRetryableDraft(): void
    {
        $crawler = $this->browser->request('GET', '/inventory/capture');
        $token = $crawler->filter('[data-capture-csrf-token-value]')->attr('data-capture-csrf-token-value');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $photos = [];
            $recordings = [];
            for ($i = 0; $i < 3; ++$i) {
                $path = tempnam(sys_get_temp_dir(), 'inventory-photo-');
                file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII='));
                $photos[] = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'photo.png', 'image/png', test: true);
            }
            for ($i = 0; $i < 2; ++$i) {
                $path = tempnam(sys_get_temp_dir(), 'inventory-audio-');
                file_put_contents($path, 'RIFF'.pack('V', 2036).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 2000).str_repeat("\0", 2000));
                $recordings[] = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'note.wav', 'audio/wav', test: true);
            }
            $this->browser->request('POST', '/inventory/capture', [
                'client_id' => 'same-capture', 'metadata' => json_encode(['transcript' => 'Shelf B. Missing tip.']),
            ], ['photos' => $photos, 'audio_notes' => $recordings], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(201);
            $result = json_decode($this->browser->getResponse()->getContent(), true);
            self::assertSame('/inventory/items/'.$result['id'], $result['reviewUrl']);
            foreach (array_merge($photos, $recordings) as $file) {
                if (is_file($file->getPathname())) unlink($file->getPathname());
            }
        }
        $this->em->clear();
        $item = $this->em->getRepository(Item::class)->findOneBy(['clientId' => 'same-capture']);
        self::assertCount(3, $item->getPhotos());
        self::assertCount(2, $item->getAudioNotes());
        self::assertSame('Shelf B. Missing tip.', $item->getTranscript());
        self::assertNull($item->getInventorySavedAt());
        self::assertTrue($item->isInventoryIntake());
        self::assertFalse($item->printRequested);
        self::assertSame(2, $this->em->getRepository(Item::class)->count([]));
    }

    public function testFailedIdentificationAllowsManualSave(): void
    {
        $item = $this->em->find(Item::class, $this->itemId);
        $item->marking = ItemFlow::PLACE_FAILED;
        $this->em->flush();
        $crawler = $this->browser->request('GET', '/inventory/items/'.$this->itemId);
        self::assertSelectorTextContains('.inventory-record', 'Identification could not finish');
        $this->browser->submit($crawler->selectButton('Save to collection')->form(['title' => 'Shower seat']));
        self::assertResponseRedirects();
        $this->browser->followRedirect();
        self::assertSelectorTextContains('h2', 'Shower seat');
    }

    public function testCsrfFailureCannotSave(): void
    {
        $this->browser->request('POST', '/inventory/items/'.$this->itemId.'/save', ['title' => 'Forged', 'collection' => 'medical']);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Item::class, $this->itemId)->getInventorySavedAt());
    }

    public function testCaptureUsesExistingControllerInInventoryMode(): void
    {
        $this->browser->request('GET', '/inventory/capture');
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('InventoryIt');
        self::assertSelectorExists('[data-capture-inventory-value="true"]');
        self::assertSelectorExists('[data-capture-target="recordings"]');
        self::assertSelectorNotExists('[data-capture-target="printToggle"]');
        self::assertSelectorNotExists('[data-capture-target="price"]');
    }

    public function testAnonymousCannotReadInventoryOrApi(): void
    {
        $this->browser->request('GET', '/logout');
        $this->browser->request('GET', '/inventory');
        self::assertResponseRedirects('/login');
        $this->browser->request('GET', '/api/items');
        self::assertResponseRedirects('/login');
    }
}
