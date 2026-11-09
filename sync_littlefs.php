<?php
// Project: datalogger9_remote
// Receives one already-combined nine-column CSV record from LittleFS.
// Uses existing Render environment variables API_TOKEN / ESP_API_TOKEN.
// Compatible with ESP8266 and ESP32 firmware.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';


/* ---------------------------------------------------------
   1. JSON response helper
   --------------------------------------------------------- */

function syncReply(int $code, array $body): void
{
    http_response_code($code);

    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* ---------------------------------------------------------
   2. Append combined record to server CSV
   --------------------------------------------------------- */

function appendServerCsv(
    string $csvFile,
    string $csvId,
    string $value1,
    string $value2,
    string $value3,
    string $date,
    string $time,
    string $timestamp,
    string $timezone,
    string $deviceId
): array {

    $header = [
        'id',
        'value1',
        'value2',
        'value3',
        'Date',
        'Time',
        'TimeStamp',
        'timezone',
        'device_id'
    ];

    $fp = @fopen($csvFile, 'c+');

    if ($fp === false) {
        throw new RuntimeException(
            'Cannot create/open server sensor_data.csv'
        );
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        throw new RuntimeException(
            'Could not lock server sensor_data.csv'
        );
    }

    rewind($fp);

    $first = fgetcsv($fp);

    $maxId = 0;
    $exists = false;

    if (
        $first === false ||
        (count($first) === 1 && trim((string)$first[0]) === '')
    ) {

        ftruncate($fp, 0);
        rewind($fp);

        fputcsv($fp, $header);

    } else {

        $normalized = array_map(
            static fn($x) => strtolower(trim((string)$x)),
            $first
        );

        if ($normalized !== array_map('strtolower', $header)) {

            flock($fp, LOCK_UN);
            fclose($fp);

            throw new RuntimeException(
                'Server sensor_data.csv header is not the required nine-column format'
            );
        }

        while (($row = fgetcsv($fp)) !== false) {

            if (
                isset($row[0]) &&
                ctype_digit((string)$row[0])
            ) {
                $maxId = max($maxId, (int)$row[0]);
            }

            if (
                count($row) >= 9 &&
                (string)$row[6] === $timestamp &&
                (string)$row[8] === $deviceId
            ) {
                $exists = true;
            }
        }
    }

    $appended = false;
    $serverId = null;

    if (!$exists) {

        $serverId = $maxId + 1;

        fseek($fp, 0, SEEK_END);

        if (
            fputcsv(
                $fp,
                [
                    $serverId,
                    $value1,
                    $value2,
                    $value3,
                    $date,
                    $time,
                    $timestamp,
                    $timezone,
                    $deviceId
                ]
            ) === false
        ) {

            flock($fp, LOCK_UN);
            fclose($fp);

            throw new RuntimeException(
                'Could not append server CSV record'
            );
        }

        fflush($fp);

        $appended = true;
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return [
        'csv_appended' => $appended,
        'server_csv_id' => $serverId
    ];
}


/* ---------------------------------------------------------
   3. Main historical synchronization
   --------------------------------------------------------- */

try {

    /* Only POST requests are accepted. */

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

        header('Allow: POST');

        syncReply(
            405,
            [
                'ok' => false,
                'message' => 'POST requests only'
            ]
        );
    }


    /* -----------------------------------------------------
       4. API token authentication

       Supports both existing Render variables:
       API_TOKEN and ESP_API_TOKEN.

       Supports both request methods:
       X-API-Token header and api_token POST field.
       ----------------------------------------------------- */

    $expectedToken = trim(
        (string)getenv('API_TOKEN')
    );

    if ($expectedToken === '') {

        $expectedToken = trim(
            (string)getenv('ESP_API_TOKEN')
        );
    }

    $providedToken = trim(
        (string)(
            $_SERVER['HTTP_X_API_TOKEN']
            ?? $_POST['api_token']
            ?? ''
        )
    );

    if (
        $expectedToken === '' ||
        $providedToken === '' ||
        !hash_equals($expectedToken, $providedToken)
    ) {

        syncReply(
            401,
            [
                'ok' => false,
                'message' => 'Invalid API token'
            ]
        );
    }


    /* -----------------------------------------------------
       5. Read CSV fields
       ----------------------------------------------------- */

    $csvId = trim(
        (string)($_POST['csv_id'] ?? $_POST['id'] ?? '')
    );

    $value1 = trim((string)($_POST['value1'] ?? ''));
    $value2 = trim((string)($_POST['value2'] ?? ''));
    $value3 = trim((string)($_POST['value3'] ?? ''));

    $date = trim((string)($_POST['Date'] ?? ''));
    $time = trim((string)($_POST['Time'] ?? ''));

    $timestamp = trim(
        (string)($_POST['TimeStamp'] ?? '')
    );

    $timezone = trim(
        (string)($_POST['timezone'] ?? '')
    );

    $deviceId = trim(
        (string)($_POST['device_id'] ?? '')
    );


    /* -----------------------------------------------------
       6. Validate CSV record ID
       ----------------------------------------------------- */

    if ($csvId === '' || !ctype_digit($csvId)) {

        syncReply(
            400,
            [
                'ok' => false,
                'message' => 'Invalid CSV record ID'
            ]
        );
    }


    /* -----------------------------------------------------
       7. Validate sensor values
       ----------------------------------------------------- */

    if (
        $value1 === '' ||
        $value2 === '' ||
        $value3 === '' ||
        !is_numeric($value1) ||
        !is_numeric($value2) ||
        !is_numeric($value3)
    ) {

        syncReply(
            400,
            [
                'ok' => false,
                'message' => 'All three combined sensor values must be numeric'
            ]
        );
    }


    /* -----------------------------------------------------
       8. Validate field lengths
       ----------------------------------------------------- */

   $lengths = [
    'value1' => strlen($value1),
    'value2' => strlen($value2),
    'value3' => strlen($value3),
    'timezone' => strlen($timezone),
    'device_id' => strlen($deviceId)
];

$limits = [
    'value1' => 30,
    'value2' => 30,
    'value3' => 50,
    'timezone' => 64,
    'device_id' => 32
];

$tooLong = [];

foreach ($lengths as $field => $length) {
    if ($length > $limits[$field]) {
        $tooLong[$field] = [
            'actual_length' => $length,
            'maximum_length' => $limits[$field]
        ];
    }
}

if (!empty($tooLong)) {
    syncReply(400, [
        'ok' => false,
        'message' => 'Field length validation failed',
        'fields' => $tooLong
    ]);
}


    /* -----------------------------------------------------
       9. Validate combined device ID
       ----------------------------------------------------- */

    if ($deviceId !== 'ESP0001,ESP0002,ESP0003') {

        syncReply(
            400,
            [
                'ok' => false,
                'message' => 'Unexpected combined device_id'
            ]
        );
    }


    /* -----------------------------------------------------
       10. Validate date and time formats
       ----------------------------------------------------- */

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $timestamp
        )
    ) {

        syncReply(
            400,
            [
                'ok' => false,
                'message' => 'Invalid Date, Time or TimeStamp format'
            ]
        );
    }


    /* -----------------------------------------------------
       11. Validate IANA timezone
       ----------------------------------------------------- */

    try {

        new DateTimeZone($timezone);

    } catch (Throwable $e) {

        syncReply(
            400,
            [
                'ok' => false,
                'message' => 'Invalid timezone'
            ]
        );
    }


    /* -----------------------------------------------------
       12. Check database connection
       ----------------------------------------------------- */

    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {

        throw new RuntimeException(
            'Database connection unavailable'
        );
    }

    $conn->query("SET time_zone = '+00:00'");


    /* -----------------------------------------------------
       13. Check whether record already exists in logs
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        "SELECT id
         FROM logs
         WHERE device_id = ?
           AND TimeStamp = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        'ss',
        $deviceId,
        $timestamp
    );

    $stmt->execute();
    $stmt->store_result();

    $alreadyInDb = ($stmt->num_rows > 0);

    $existingDbId = null;

    if ($alreadyInDb) {

        $stmt->bind_result($existingDbId);
        $stmt->fetch();
    }

    $stmt->close();


    /* -----------------------------------------------------
       14. Insert into TiDB logs if not already present
       ----------------------------------------------------- */

    $dbInserted = false;

    $dbId = $existingDbId;

    if (!$alreadyInDb) {

        $stmt = $conn->prepare(
            "INSERT INTO logs
                (
                    value1,
                    value2,
                    value3,
                    `Date`,
                    `Time`,
                    `TimeStamp`,
                    timezone,
                    device_id
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'ssssssss',
            $value1,
            $value2,
            $value3,
            $date,
            $time,
            $timestamp,
            $timezone,
            $deviceId
        );

        $stmt->execute();

        $dbInserted = true;

        $dbId = $conn->insert_id;

        $stmt->close();
    }


    /* -----------------------------------------------------
       15. Append record to server sensor_data.csv
       ----------------------------------------------------- */

    $csvResult = appendServerCsv(
        __DIR__ . DIRECTORY_SEPARATOR . 'sensor_data.csv',
        $csvId,
        $value1,
        $value2,
        $value3,
        $date,
        $time,
        $timestamp,
        $timezone,
        $deviceId
    );


    /* -----------------------------------------------------
       16. Successful response
       ----------------------------------------------------- */

    syncReply(
        200,
        [
            'ok' => true,
            'message' => 'Combined historical record synchronized; duplicates were skipped',

            'csv_id' => $csvId,
            'device_id' => $deviceId,
            'timestamp' => $timestamp,

            'database_inserted' => $dbInserted,
            'already_in_database' => $alreadyInDb,
            'mysql_id' => $dbId,

            'csv_appended' => $csvResult['csv_appended'],
            'server_csv_id' => $csvResult['server_csv_id']
        ]
    );


} catch (Throwable $e) {

    error_log(
        'combined CSV sync failed: ' . $e->getMessage()
    );

    syncReply(
        500,
        [
            'ok' => false,
            'message' => 'Server could not process historical record'
        ]
    );
}
