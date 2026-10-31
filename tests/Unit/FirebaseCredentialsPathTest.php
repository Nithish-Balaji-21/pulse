<?php

use App\Services\FirebaseService;

it('falls back to the credentials file under storage when FCM_CREDENTIALS is unset or blank', function (?string $value) {
    config(['services.fcm.credentials' => $value]);
    expect(FirebaseService::credentialsPath())
        ->toBe(storage_path('app/firebase/firebase-credentials.json'));
})->with([null, '', '  ']);

it('resolves a relative FCM_CREDENTIALS path against the project root', function () {
    config(['services.fcm.credentials' => 'secrets/firebase.json']);
    expect(FirebaseService::credentialsPath())->toBe(base_path('secrets/firebase.json'));
});

it('keeps an absolute FCM_CREDENTIALS path as is', function (string $path) {
    config(['services.fcm.credentials' => $path]);
    expect(FirebaseService::credentialsPath())->toBe($path);
})->with([
    '/etc/coiiot/firebase.json',
    'C:\\secrets\\firebase.json',
    'D:/secrets/firebase.json',
]);
