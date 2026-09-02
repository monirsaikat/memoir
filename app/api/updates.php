<?php

switch ($action) {

case 'update-status':
    require_method('GET');
    require_once $projectRoot . '/updater.php';
    try {
        json_response(memoir_check_for_updates(false));
    } catch (Throwable $error) {
        json_response(['ok' => false, 'message' => $error->getMessage()], 503);
    }

case 'check-update':
    require_method('POST');
    require_once $projectRoot . '/updater.php';
    try {
        json_response(memoir_check_for_updates(true));
    } catch (Throwable $error) {
        json_response(['ok' => false, 'message' => $error->getMessage()], 503);
    }

case 'install-update':
    require_method('POST');
    require_once $projectRoot . '/updater.php';
    $data = request_json();
    try {
        json_response(memoir_install_update((string) ($data['version'] ?? '')));
    } catch (Throwable $error) {
        json_response(['ok' => false, 'message' => $error->getMessage()], 422);
    }

}
