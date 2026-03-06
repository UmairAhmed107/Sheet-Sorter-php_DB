<?php
session_start();

/* ================= SESSION PROTECTION ================= */
if(!isset($_SESSION['user'])){
    header("Location: login.php");
    exit();
}
/* ====================================================== */




date_default_timezone_set('Asia/Kolkata');

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$eventFile = "events.json";
$historyFile = "history.json";

/* ================= EVENT REGISTRATION ================= */
if (isset($_POST['register_event'])) {
    $events = file_exists($eventFile) ? json_decode(file_get_contents($eventFile), true) : [];

    $time = $_POST['from_hour'].":".$_POST['from_minute']." ".$_POST['from_ampm'] . " - " .
            $_POST['to_hour'].":".$_POST['to_minute']." ".$_POST['to_ampm'];

    $events[] = [
        "name" => $_POST['event_name'],
        "venue" => $_POST['event_venue'],
        "date" => $_POST['event_date'],
        "time" => $time
    ];

    file_put_contents($eventFile, json_encode($events, JSON_PRETTY_PRINT));
    header("Location: register_event.php");
    exit();
}

/* ================= SEGREGATION ================= */
if (isset($_POST['segregate'])) {

    if (!file_exists("downloads")) mkdir("downloads", 0777, true);

    $events = json_decode(file_get_contents($eventFile), true);
    $selectedEvent = $_POST['selected_event'];

    foreach ($events as $event) {
        if ($event['name'] == $selectedEvent) {
            $eventDate = date("d-m-Y", strtotime($event['date']));
            $eventTime = $event['time'];
        }
    }

    $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
    $data = $spreadsheet->getActiveSheet()->toArray();

    $schoolCodes = [
        "SENSE" => ["BVD","BEC","BML"],
        "SCOPE" => ["BAI","MID","BCI","BKT","BCE"],
        "SCORE" => ["BYB","BDE","MIS"],
        "SAS" => ["MDT","MSP"],
        "SELECT" => ["BEE","BEL","BEI"],
        "SMEC" => ["BMV","BST","BMA","BME","BMM"],
        "SCE" => ["BCL"],
        "SHINE" => ["BHT"],
        "SCHEME" => ["BCM"],
        "VAIAL" => ["BAG"],
        "SSL" => ["BFN","BBC"],
        "VSMART" => ["BVC"]
    ];

    $createdFiles = [];
    $schoolCounts = [];
    $totalStudents = 0;

    foreach ($schoolCodes as $school => $codes) {

        $newSpreadsheet = new Spreadsheet();
        $sheet = $newSpreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', "Event: $selectedEvent");
        $sheet->setCellValue('A2', "Date: $eventDate | Timing: $eventTime");

        $colIndex = 1;
        foreach ($codes as $code) {
            $columnLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($columnLetter . '4', $code);
            $colIndex++;
        }

        $rowCounters = [];
        foreach ($codes as $code) { $rowCounters[$code] = 5; }

        $schoolStudentCount = 0;

        foreach ($data as $row) {
            $regNo = trim($row[0]);

            if (strlen($regNo) == 9) {
                $code = substr($regNo, 2, 3);

                if (in_array($code, $codes)) {
                    $colPosition = array_search($code, $codes) + 1;
                    $columnLetter = Coordinate::stringFromColumnIndex($colPosition);
                    $currentRow = $rowCounters[$code];

                    $sheet->setCellValue($columnLetter . $currentRow, $regNo);

                    $rowCounters[$code]++;
                    $schoolStudentCount++;
                }
            }
        }

        if ($schoolStudentCount > 0) {
            $cleanEvent = preg_replace('/[^A-Za-z0-9]/', '_', $selectedEvent);
            $fileName = $school . "_" . $cleanEvent . "_" . $eventDate . ".xlsx";
            $filePath = "downloads/" . $fileName;

            $writer = new Xlsx($newSpreadsheet);
            $writer->save($filePath);

            $createdFiles[] = $fileName;
        }

        $schoolCounts[$school] = $schoolStudentCount;
        $totalStudents += $schoolStudentCount;
    }

    $cleanEvent = preg_replace('/[^A-Za-z0-9]/', '_', $selectedEvent);
    $summaryName = $cleanEvent . "_summary.txt";
    $summaryPath = "downloads/" . $summaryName;

    $summaryContent = "Event: $selectedEvent\nDate: $eventDate\n\nSchool-wise Student Count:\n\n";
    foreach ($schoolCounts as $school => $count) {
        $summaryContent .= $school . " : " . $count . "\n";
    }

    $summaryContent .= "\n----------------------------------\nTOTAL STUDENTS : " . $totalStudents . "\n";

    file_put_contents($summaryPath, $summaryContent);
    $createdFiles[] = $summaryName;

    $zipName = "All_Schools_" . $cleanEvent . "_" . $eventDate . ".zip";
    $zipPath = "downloads/" . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($createdFiles as $file) {
            $zip->addFile("downloads/".$file, $file);
        }
        $zip->close();
    }

    $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    $history[] = [
        "event_name" => $selectedEvent,
        "event_date" => $eventDate,
        "event_time" => $eventTime,
        "segregated_on" => date("d-m-Y h:i A"),
        "total_files" => count($createdFiles)
    ];

    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));

    $_SESSION['zip'] = $zipName;
    $_SESSION['files'] = $createdFiles;
    $_SESSION['segregated_event'] = $selectedEvent;

    header("Location: register_event.php?segregation=done");
    exit();
}
?>