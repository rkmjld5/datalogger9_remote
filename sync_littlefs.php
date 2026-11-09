<?php
/*
 * Project: datalogger9_remote
 *
 * LittleFS historical CSV synchronization.
 *
 * Receives one combined nine-column CSV record.
 * Inserts the record into TiDB logs if it does not already exist.
 * Also appends the record to server sensor_data.csv.
 *
 * Compatible with ESP8266 and ESP32.
 *
 * Authentication:
 *   API_TOKEN or ESP_API_TOKEN
 *
 * CSV format:
 * id,value1,value2,value3,Date,Time,TimeStamp,timezone,device_id
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';


/* ---------------------------------------------------------
   1. JSON response
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
   2. Normalize combined device ID
   --------------------------------------------------------- */

function normalizeCombinedDeviceId(string $raw): string
{
    /*
     * The combined device set must contain exactly:
     * ESP0001, ESP0002 and ESP0003.
     *
     * Extract device identifiers even if the submitted
     * field contains repeated identifiers or separators.
     */

    preg_match_all(
        '/ESP\d{4}/i',
        $raw,
        $matches
    );

    $devices = array_map(
        'strtoupper',
        $matches[0]
    );

    $devices = array_values(
        array_unique($devices)
    );

    sort($devices);

    $expected = [
        'ESP0001',
        'ESP0002',
        'ESP0003'
    ];

    sort($expected);

    if ($devices !== $expected) {
        throw new InvalidArgumentException(
            'Invalid combined device_id. Expected ESP0001, ESP0002 and ESP0003.'
        );
    }

    return 'ESP0001,ESP0002,ESP0003';
}


/* ---------------------------------------------------------
   3. Append record to server sensor_data.csv
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
        (count($first) === 1 &&
         trim((string)$first[0]) === '')
    ) {

        ftruncate($fp, 0);
        rewind($fp);

        fputcsv($fp, $header);

    } else {

        $normalized = array_map(
            static fn($x) => strtolower(trim((string)$x)),
            $first
        );

        if (
            $normalized !==
            array_map('strtolower', $header)
        ) {

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
                $maxId = max(
                    $maxId,
                    (int)$row[0]
                );
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
   4. Main synchronization
   --------------------------------------------------------- */

try {

    /* Only POST is accepted. */

    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    ) {

        header('Allow: POST');

        syncReply(405, [
            'ok' => false,
            'message' => 'POST requests only'
        ]);
    }


    /* -----------------------------------------------------
       5. API authentication
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

        syncReply(401, [
            'ok' => false,
            'message' => 'Invalid API token'
        ]);
    }


    /* -----------------------------------------------------
       6. Read submitted CSV fields
       ----------------------------------------------------- */

    $csvId = trim(
        (string)(
            $_POST['csv_id']
            ?? $_POST['id']
            ?? ''
        )
    );

    $value1 = trim(
        (string)($_POST['value1'] ?? '')
    );

    $value2 = trim(
        (string)($_POST['value2'] ?? '')
    );

    $value3 = trim(
        (string)($_POST['value3'] ?? '')
    );

    $date = trim(
        (string)($_POST['Date'] ?? '')
    );

    $time = trim(
        (string)($_POST['Time'] ?? '')
    );

    $timestamp = trim(
        (string)($_POST['TimeStamp'] ?? '')
    );

    $timezone = trim(
        (string)($_POST['timezone'] ?? '')
    );

    $rawDeviceId = trim(
        (string)($_POST['device_id'] ?? '')
    );


    /* -----------------------------------------------------
       7. Validate CSV ID
       ----------------------------------------------------- */

    if (
        $csvId === '' ||
        !ctype_digit($csvId)
    ) {

        syncReply(400, [
            'ok' => false,
            'message' => 'Invalid CSV record ID'
        ]);
    }


    /* -----------------------------------------------------
       8. Validate sensor values
       ----------------------------------------------------- */

    if (
        $value1 === '' ||
        $value2 === '' ||
        $value3 === '' ||
        !is_numeric($value1) ||
        !is_numeric($value2) ||
        !is_numeric($value3)
    ) {

        syncReply(400, [
            'ok' => false,
            'message' => 'All three combined sensor values must be numeric'
        ]);
    }


    /* -----------------------------------------------------
       9. Validate field lengths
       ----------------------------------------------------- */

    if (
        strlen($value1) > 30 ||
        strlen($value2) > 30 ||
        strlen($value3) > 50 ||
        strlen($timezone) > 64
    ) {

        syncReply(400, [
            'ok' => false,
            'message' => 'A sensor value or timezone exceeds its database column length'
        ]);
    }


    /* -----------------------------------------------------
       10. Normalize device ID
       ----------------------------------------------------- */

    try {

        $deviceId = normalizeCombinedDeviceId(
            $rawDeviceId
        );

    } catch (InvalidArgumentException $e) {

        syncReply(400, [
            'ok' => false,
            'message' => $e->getMessage()
        ]);
    }


    /* -----------------------------------------------------
       11. Validate date and time
       ----------------------------------------------------- */

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $timestamp
        )
    ) {

        syncReply(400, [
            'ok' => false,
            'message' => 'Invalid Date, Time or TimeStamp format'
        ]);
    }


    /* -----------------------------------------------------
       12. Validate timezone
       ----------------------------------------------------- */

    try {

        new DateTimeZone($timezone);

    } catch (Throwable $e) {

        syncReply(400, [
            'ok' => false,
            'message' => 'Invalid timezone'
        ]);
    }


    /* -----------------------------------------------------
       13. Verify database connection
       ----------------------------------------------------- */

    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {

        throw new RuntimeException(
            'Database connection unavailable'
        );
    }

    $conn->query(
        "SET time_zone = '+00:00'"
    );


    /* -----------------------------------------------------
       14. Check duplicate record in TiDB
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

    $alreadyInDb = (
        $stmt->num_rows > 0
    );

    $existingDbId = null;

    if ($alreadyInDb) {

        $stmt->bind_result($existingDbId);
        $stmt->fetch();
    }

    $stmt->close();


    /* -----------------------------------------------------
       15. Insert into logs if not already present
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
       16. Append to server sensor_data.csv
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
       17. Successful response
       ----------------------------------------------------- */

    syncReply(200, [
        'ok' => true,

        'message' =>
            'Combined historical record synchronized; duplicates were skipped',

        'csv_id' => $csvId,
        'device_id' => $deviceId,
        'timestamp' => $timestamp,

        'database_inserted' => $dbInserted,
        'already_in_database' => $alreadyInDb,
        'mysql_id' => $dbId,

        'csv_appended' => $csvResult['csv_appended'],
        'server_csv_id' => $csvResult['server_csv_id']
    ]);


} catch (Throwable $e) {

    error_log(
        'LittleFS combined CSV sync failed: ' .
        $e->getMessage()
    );

    syncReply(500, [
        'ok' => false,
        'message' =>
            'Server could not process historical record'
    ]);
}
