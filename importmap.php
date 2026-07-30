<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    'admin' => ['path' => './assets/admin.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'idb' => ['version' => '8.0.3'],
    'idb-keyval' => ['version' => '6.3.0'],
    'dexie' => ['version' => '4.4.4'],
    'framework7/bundle' => ['version' => '9.1.1'],
    'framework7/css/bundle' => ['version' => '9.1.1'],
    'framework7-icons' => ['version' => '5.0.5'],
    'framework7-icons/css/framework7-icons.min.css' => ['version' => '5.0.5', 'type' => 'css'],
    'dom7' => ['version' => '4.0.6'],
    'ssr-window' => ['version' => '5.0.1'],
    'path-to-regexp' => ['version' => '6.3.0'],
    'htm' => ['version' => '3.1.1'],
    'swiper/bundle' => ['version' => '12.2.0'],
    'swiper/element/bundle' => ['version' => '12.2.0'],
    'camera-bundle/camera' => ['path' => './src/CameraBundle/assets/camera.js'],
    'camera-bundle/audio-recorder' => ['path' => './src/CameraBundle/assets/audio_recorder.js'],
    'camera-bundle/capture-queue' => ['path' => './src/CameraBundle/assets/capture_queue.js'],
    '@spomky-labs/pwa/helpers' => ['path' => './vendor/spomky-labs/pwa-bundle/assets/src/helpers.js'],
    '@survos-mobile/mobile' => ['path' => './vendor/survos/fw-bundle/assets/src/controllers/mobile_controller.js'],
    'debug' => ['version' => '4.4.3'],
    'ms' => ['version' => '2.1.3'],
    'framework7' => ['version' => '9.1.2'],
    'flag-icons/css/flag-icons.min.css' => ['version' => '7.5.0', 'type' => 'css'],
    'bootstrap' => ['version' => '5.3.8'],
    '@tabler/core' => ['version' => '1.4.0'],
    '@popperjs/core' => ['version' => '2.11.8'],
    'bootstrap/dist/css/bootstrap.min.css' => ['version' => '5.3.8', 'type' => 'css'],
    '@tabler/core/dist/css/tabler.min.css' => ['version' => '1.4.0', 'type' => 'css'],
];
