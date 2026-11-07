<?php
/*
 * Project: datalogger9_remote
 *
 * Receives readings from ESP0001, ESP0002 and ESP0003.
 * Inserts exactly one combined row into logs per completed cycle.
 *
 * IMPORTANT:
 * Only ESP0001 (MASTER) saves the combined row locally
 * to SD card and LittleFS.
 *
 * Even if ESP0002 or ESP0003 submits the final reading,
 * ESP0001 can retrieve the completed readings by retrying
 * its POST request.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

/* ---------------------------------------------------------
   1. JSON response
   --------------------------------------------------------- */

function reply(int $code, array $data): void
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* ---------------------------------------------------------
   2. Get all three stored readings for a cycle
   --------------------------------------------------------- */

function getCycleReadings(
    mysqli $conn,
    string $cycleId
): array {

    $stmt = $conn->prepare(
        "SELECT device_id, sensor_value, sample_epoch, timezone
         FROM sensor_pending
         WHERE cycle_id = ?"
    );

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();

    $readings = [];

    while ($row = $result->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();

    return $readings;
}

/* ---------------------------------------------------------
   3. Return a completed cycle to a requesting controller
   ---------------------------------------------------------

   This function is important.

   If the cycle was completed earlier by ESP0002 or ESP0003,
   ESP0001 must still receive all three readings.

   Only ESP0001 receives save_local = true.
   --------------------------------------------------------- */

function completedResponse(
    mysqli $conn,
    string $cycleId,
    string $requestDevice,
    ?string $completedBy
): void {

    $readings = getCycleReadings($conn, $cycleId);

    $required = [
        'ESP0001',
        'ESP0002',
        'ESP0003'
    ];

    foreach ($required as $device) {

        if (!isset($readings[$device])) {

            reply(500, [
                'ok' => false,
                'error' => 'Completed cycle is missing a stored controller reading',
                'cycle_id' => $cycleId
            ]);
        }
    }

    // Master sample time and timezone are used for the combined row.
    $epoch = (int)$readings['ESP0001']['sample_epoch'];

    $tzName = (string)$readings['ESP0001']['timezone'];

    $utcStamp = gmdate(
        'Y-m-d H:i:s',
        $epoch
    );

    // Only the master is allowed to save locally.
    $saveLocal = ($requestDevice === 'ESP0001');

    reply(200, [

        'ok' => true,

        'received' => 3,

        'required' => 3,

        'completed' => true,

        // Always return the exact original cycle ID.
        'cycle_id' => $cycleId,

        'completed_by' => $completedBy,

        // ESP0001 saves, ESP0002 and ESP0003 do not.
        'save_local' => $saveLocal,

        'value1' => (string)$readings['ESP0001']['sensor_value'],

        'value2' => (string)$readings['ESP0002']['sensor_value'],

        'value3' => (string)$readings['ESP0003']['sensor_value'],

        'sample_epoch' => $epoch,

        'timezone' => $tzName,

        'timestamp_utc' => $utcStamp,

        'message' => 'Completed cycle data returned successfully'

    ]);
}

/* ---------------------------------------------------------
   4. Main processing
   --------------------------------------------------------- */

try {

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

        reply(405, [
            'ok' => false,
            'error' => 'POST requests only'
        ]);
    }

    /* -----------------------------------------------------
       API token authentication
       ----------------------------------------------------- */

    $expected = trim((string)getenv('ESP_API_TOKEN'));

    $provided = trim(
        (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '')
    );

    if (
        $expected === '' ||
        !hash_equals($expected, $provided)
    ) {

        reply(401, [
            'ok' => false,
            'error' => 'Invalid API token'
        ]);
    }

    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {

        throw new RuntimeException(
            'Database connection unavailable'
        );
    }

    // All database timestamps are handled in UTC.
    $conn->query("SET time_zone = '+00:00'");

    /* -----------------------------------------------------
       Read JSON submitted by ESP8266
       ----------------------------------------------------- */

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {

        reply(400, [
            'ok' => false,
            'error' => 'Invalid JSON'
        ]);
    }

    $device = trim(
        (string)($input['device_id'] ?? '')
    );

    $value = trim(
        (string)($input['value'] ?? '')
    );

    $cycleId = trim(
        (string)($input['cycle_id'] ?? '')
    );

    $sampleEpoch = filter_var(
        $input['sample_epoch'] ?? null,
        FILTER_VALIDATE_INT
    );

    $timezone = trim(
        (string)($input['timezone'] ?? '')
    );

    $allowed = [
        'ESP0001',
        'ESP0002',
        'ESP0003'
    ];

    if (!in_array($device, $allowed, true)) {

        reply(400, [
            'ok' => false,
            'error' => 'Unknown device ID'
        ]);
    }

    if (
        $value === '' ||
        !is_numeric($value) ||
        strlen($value) > 50
    ) {

        reply(400, [
            'ok' => false,
            'error' => 'Invalid sensor value'
        ]);
    }

    if (
        $cycleId === '' ||
        !ctype_digit($cycleId) ||
        $sampleEpoch === false ||
        $sampleEpoch <= 0
    ) {

        reply(400, [
            'ok' => false,
            'error' => 'Invalid cycle or sample time'
        ]);
    }

    if ($timezone === '') {

        reply(400, [
            'ok' => false,
            'error' => 'Timezone is required'
        ]);
    }

    try {

        new DateTimeZone($timezone);

    } catch (Throwable $e) {

        reply(400, [
            'ok' => false,
            'error' => 'Invalid timezone'
        ]);
    }

    /* -----------------------------------------------------
       Start transaction and lock cycle record
       ----------------------------------------------------- */

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT completed, created_at, completed_by
         FROM sensor_cycles
         WHERE cycle_id = ?
         FOR UPDATE"
    );

    $stmt->bind_param('s', $cycleId);

    $stmt->execute();

    $cycle = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$cycle) {

        $conn->rollback();

        reply(409, [
            'ok' => false,
            'error' => 'Cycle not found. Obtain a current cycle ID.',
            'cycle_id' => $cycleId
        ]);
    }

    /* -----------------------------------------------------
       IMPORTANT: Handle an already-completed cycle
       ----------------------------------------------------- */

    if ((int)$cycle['completed'] === 1) {

        $completedBy = isset($cycle['completed_by'])
            ? (string)$cycle['completed_by']
            : null;

        $conn->commit();

        /*
         * Do not insert another logs row.
         *
         * Return the original stored readings.
         *
         * ESP0001 receives save_local = true even when
         * ESP0002 or ESP0003 completed the cycle.
         */

        completedResponse(
            $conn,
            $cycleId,
            $device,
            $completedBy
        );
    }

    /* -----------------------------------------------------
       Check cycle expiration
       ----------------------------------------------------- */

    $created = strtotime(
        (string)$cycle['created_at']
    );

    if (
        $created === false ||
        (time() - $created) > 120
    ) {

        $conn->rollback();

        reply(409, [
            'ok' => false,
            'error' => 'Cycle expired. Wait for a new cycle.',
            'cycle_id' => $cycleId
        ]);
    }

    /* -----------------------------------------------------
       Insert or update this controller reading
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        "INSERT INTO sensor_pending
            (cycle_id, device_id, sensor_value,
             sample_epoch, timezone)
         VALUES (?, ?, ?, ?, ?)

         ON DUPLICATE KEY UPDATE
            sensor_value = VALUES(sensor_value),
            sample_epoch = VALUES(sample_epoch),
            timezone = VALUES(timezone),
            received_at = CURRENT_TIMESTAMP"
    );

    $stmt->bind_param(
        'sssis',
        $cycleId,
        $device,
        $value,
        $sampleEpoch,
        $timezone
    );

    $stmt->execute();

    $stmt->close();

    /* -----------------------------------------------------
       Count received controllers
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT device_id) AS total
         FROM sensor_pending
         WHERE cycle_id = ?"
    );

    $stmt->bind_param('s', $cycleId);

    $stmt->execute();

    $received = (int)$stmt->get_result()
        ->fetch_assoc()['total'];

    $stmt->close();

    /* -----------------------------------------------------
       If fewer than three readings, wait
       ----------------------------------------------------- */

    if ($received < 3) {

        $conn->commit();

        reply(200, [

            'ok' => true,

            'received' => $received,

            'required' => 3,

            'completed' => false,

            'save_local' => false,

            'cycle_id' => $cycleId,

            'message' => 'Waiting for remaining controller readings'

        ]);
    }

    /* -----------------------------------------------------
       Fetch all three readings
       ----------------------------------------------------- */

    $readings = getCycleReadings(
        $conn,
        $cycleId
    );

    foreach ($allowed as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {

            $conn->commit();

            reply(200, [

                'ok' => true,

                'received' => count($readings),

                'required' => 3,

                'completed' => false,

                'save_local' => false,

                'cycle_id' => $cycleId,

                'message' => 'Waiting for all three controller readings'

            ]);
        }
    }

    /* -----------------------------------------------------
       Calculate master local date and time
       ----------------------------------------------------- */

    $masterEpoch = (int)$readings['ESP0001']['sample_epoch'];

    $masterTimezone = (string)$readings['ESP0001']['timezone'];

    $masterTz = new DateTimeZone($masterTimezone);

    $localDateTime = (
        new DateTimeImmutable('@' . $masterEpoch)
    )->setTimezone($masterTz);

    $dateValue = $localDateTime->format('Y-m-d');

    $timeValue = $localDateTime->format('H:i:s');

    $utcTimestamp = gmdate(
        'Y-m-d H:i:s',
        $masterEpoch
    );

    /* -----------------------------------------------------
       Prepare combined values
       ----------------------------------------------------- */

    $value1 = (string)$readings['ESP0001']['sensor_value'];

    $value2 = (string)$readings['ESP0002']['sensor_value'];

    $value3 = (string)$readings['ESP0003']['sensor_value'];

    $allDevices = 'ESP0001,ESP0002,ESP0003';

    /* -----------------------------------------------------
       Insert exactly one combined row into logs
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        "INSERT INTO logs
            (value1, value2, value3,
             `Date`, `Time`, `TimeStamp`,
             timezone, device_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        'ssssssss',
        $value1,
        $value2,
        $value3,
        $dateValue,
        $timeValue,
        $utcTimestamp,
        $masterTimezone,
        $allDevices
    );

    $stmt->execute();

    $logId = $conn->insert_id;

    $stmt->close();

    /* -----------------------------------------------------
       Mark cycle completed
       ----------------------------------------------------- */

    $completedBy = $device;

    $stmt = $conn->prepare(
        "UPDATE sensor_cycles
         SET completed = 1,
             completed_by = ?
         WHERE cycle_id = ?"
    );

    $stmt->bind_param(
        'ss',
        $completedBy,
        $cycleId
    );

    $stmt->execute();

    $stmt->close();

    /* -----------------------------------------------------
       Commit transaction
       ----------------------------------------------------- */

    $conn->commit();

    /* -----------------------------------------------------
       Return completed result
       -----------------------------------------------------

       Only ESP0001 saves locally.

       If ESP0002 or ESP0003 completed the cycle,
       ESP0001 will retrieve the same readings on retry.
       ----------------------------------------------------- */

    $saveLocal = ($device === 'ESP0001');

    reply(200, [

        'ok' => true,

        'received' => 3,

        'required' => 3,

        'completed' => true,

        'cycle_id' => $cycleId,

        'completed_by' => $completedBy,

        'save_local' => $saveLocal,

        'log_id' => $logId,

        'value1' => $value1,

        'value2' => $value2,

        'value3' => $value3,

        'sample_epoch' => $masterEpoch,

        'timezone' => $masterTimezone,

        'timestamp_utc' => $utcTimestamp,

        'message' => 'Combined row inserted into logs'

    ]);

} catch (Throwable $e) {

    if (
        isset($conn) &&
        $conn instanceof mysqli
    ) {

        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    error_log(
        'receive_sensor.php: ' . $e->getMessage()
    );

    reply(500, [
        'ok' => false,
        'error' => 'Server processing failed. Check Render logs.'
    ]);
}
