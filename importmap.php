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
 *     esm?: bool,                 // Whether jsDelivr's ESM build is used, defaults to true
 * }>
 */
return [
    '@floating-ui/core' => ['version' => '1.8.0'],
    '@floating-ui/dom' => ['version' => '1.8.0'],
    '@floating-ui/utils' => ['version' => '0.2.12'],
    '@floating-ui/utils/dom' => ['version' => '0.2.12'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@hpcc-js/wasm/graphviz' => ['version' => '2.20.0'],
    '@spomky-labs/pwa/helpers' => ['path' => './vendor/spomky-labs/pwa-bundle/assets/src/helpers.js'],
    '@survos-mobile/mobile' => ['path' => './vendor/survos/fw-bundle/assets/src/controllers/mobile_controller.js'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@tabler/core' => ['version' => '1.6.1'],
    '@tabler/core/dist/css/tabler.min.css' => ['version' => '1.6.1', 'type' => 'css'],
    'admin' => ['path' => './assets/admin.js', 'entrypoint' => true],
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    'camera-bundle/audio-recorder' => ['path' => './src/CameraBundle/assets/audio_recorder.js'],
    'camera-bundle/camera' => ['path' => './src/CameraBundle/assets/camera.js'],
    'camera-bundle/capture-queue' => ['path' => './src/CameraBundle/assets/capture_queue.js'],
    'camera-bundle/speech' => ['path' => './src/CameraBundle/assets/speech.js'],
    'd3-color' => ['version' => '3.0.1'],
    'd3-dispatch' => ['version' => '3.0.1'],
    'd3-drag' => ['version' => '3.0.0'],
    'd3-ease' => ['version' => '3.0.1'],
    'd3-format' => ['version' => '3.1.0'],
    'd3-graphviz' => ['version' => '5.6.0'],
    'd3-interpolate' => ['version' => '3.0.1'],
    'd3-path' => ['version' => '3.1.0'],
    'd3-selection' => ['version' => '3.0.0'],
    'd3-timer' => ['version' => '3.0.1'],
    'd3-transition' => ['version' => '3.0.1'],
    'd3-zoom' => ['version' => '3.0.0'],
    'debug' => ['version' => '4.4.3'],
    'dexie' => ['version' => '4.4.4'],
    'dom7' => ['version' => '4.0.6'],
    'flag-icons/css/flag-icons.min.css' => ['version' => '7.5.0', 'type' => 'css'],
    'framework7' => ['version' => '9.1.2'],
    'framework7-icons' => ['version' => '5.0.5'],
    'framework7-icons/css/framework7-icons.min.css' => ['version' => '5.0.5', 'type' => 'css'],
    'framework7/bundle' => ['version' => '9.1.1'],
    'framework7/css/bundle' => ['version' => '9.1.1'],
    'htm' => ['version' => '3.1.1'],
    'idb' => ['version' => '8.0.3'],
    'idb-keyval' => ['version' => '6.3.0'],
    'marked' => ['version' => '18.0.14'],
    'ms' => ['version' => '2.1.3'],
    'path-to-regexp' => ['version' => '6.3.0'],
    'ssr-window' => ['version' => '5.0.1'],
    'swiper/bundle' => ['version' => '12.2.0'],
    'swiper/element/bundle' => ['version' => '12.2.0'],
];
