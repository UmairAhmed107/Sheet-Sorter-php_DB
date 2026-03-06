<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require 'vendor/autoload.php';
require 'db.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* ===== LOAD DATA FROM DB ===== */
$eventsRaw = $pdo->query("SELECT * FROM events ORDER BY date ASC, id ASC")->fetchAll();
$events = [];
foreach ($eventsRaw as $row) {
    $events[] = [
        'id'              => $row['id'],
        'name'            => $row['name'],
        'venue'           => $row['venue'],
        'organising_team' => $row['organising_team'] ?? '',
        'school'          => $row['school'] ?? '',
        'phone_number'    => $row['phone_number'] ?? '',
        'event_type'      => $row['event_type'] ?? '',
        'multiday'        => (bool)$row['multiday'],
        'date'            => $row['date'],
        'end_date'        => $row['end_date'],
        'time'            => $row['time'],
        'days'            => $row['days'] ? json_decode($row['days'], true) : null,
    ];
}

$historyRaw = $pdo->query("SELECT * FROM segregation_history ORDER BY segregated_on DESC")->fetchAll();
$history = [];
foreach ($historyRaw as $row) {
    $history[] = [
        'id'             => $row['id'],
        'run_date_range' => $row['run_date_range'],
        'date_from'      => $row['date_from'],
        'date_to'        => $row['date_to'],
        'segregated_on'  => $row['segregated_on'],
        'events'         => $row['events'] ? json_decode($row['events'], true) : [],
        'zips'           => $row['zips']   ? json_decode($row['zips'],   true) : [],
    ];
}

$schoolsRaw  = $pdo->query("SELECT school_name, codes FROM schools ORDER BY school_name ASC")->fetchAll();
$schoolCodes = [];
foreach ($schoolsRaw as $row) {
    $schoolCodes[$row['school_name']] = json_decode($row['codes'], true);
}

/* ===== ANALYTICS DATA ===== */

$dayNames        = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$monthNamesShort = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// --- Core counters ---
$eventsMonthly   = array_fill(1, 12, 0);
$totalSingleDay  = 0;
$totalMultiDay   = 0;
$eventTypeCounts = [];
$eventTypesList  = ['Expert Talk','Mentoring Session','Workshop','Seminar','Boot Camp','Expo','Demo Day / Competition','Tech Fest / Hackathon / Ideathon'];
foreach ($eventTypesList as $et) $eventTypeCounts[$et] = 0;

// Busiest day of week [0=Sun..6=Sat]
$dowCounts = array_fill(0, 7, 0);

// GitHub-style heatmap: keyed by "YYYY-MM-DD" => count
$heatmapByDate = [];

// Venue utilisation
$venueCounts = [];

// Organising team leaderboard
$teamCounts = [];

foreach ($eventsRaw as $ev) {
    $ts  = strtotime($ev['date']);
    $m   = (int)date('n', $ts);
    $dw  = (int)date('w', $ts); // 0=Sun

    $eventsMonthly[$m]++;
    $dowCounts[$dw]++;

    $dateKey = date('Y-m-d', $ts);
    $heatmapByDate[$dateKey] = ($heatmapByDate[$dateKey] ?? 0) + 1;

    if ($ev['multiday']) {
        // also mark end_date and in-between days for heatmap
        $totalMultiDay++;
        $endTs = strtotime($ev['end_date'] ?? $ev['date']);
        for ($d = $ts; $d <= $endTs; $d += 86400) {
            $dk = date('Y-m-d', $d);
            $heatmapByDate[$dk] = ($heatmapByDate[$dk] ?? 0) + 1;
        }
    } else {
        $totalSingleDay++;
    }

    $et = trim($ev['event_type'] ?? '');
    if ($et) {
        if (isset($eventTypeCounts[$et])) $eventTypeCounts[$et]++;
        else $eventTypeCounts[$et] = ($eventTypeCounts[$et] ?? 0) + 1;
    }

    $venue = trim($ev['venue'] ?? '');
    if ($venue) $venueCounts[$venue] = ($venueCounts[$venue] ?? 0) + 1;

    $team = trim($ev['organising_team'] ?? '');
    if ($team) $teamCounts[$team] = ($teamCounts[$team] ?? 0) + 1;
}

arsort($venueCounts);
arsort($teamCounts);

// --- Segregation stats ---
$segregMonthly        = array_fill(1, 12, 0);
$lastSegOn            = null;
$lastEventOn          = null;
$segregatedEventNames = [];
$totalStudentsAllRuns = 0;
$runStudentCounts     = [];
$runEventCounts       = [];
$avgEventsPerRun      = 0;
$maxEventsInRun       = 0;
$minEventsInRun       = 0;

// School-wise student counts — scan downloaded xlsx files
// Each xlsx filename pattern: SCHOOL_daterange.xlsx
// We count rows in each file to get student counts per school
$schoolStudentCounts = [];
foreach (array_keys($schoolCodes) as $sc) $schoolStudentCounts[$sc] = 0;

if (file_exists("downloads")) {
    foreach (scandir("downloads") as $f) {
        if (!str_ends_with($f, '.xlsx')) continue;
        foreach (array_keys($schoolCodes) as $school) {
            if (str_starts_with($f, $school.'_')) {
                // count data rows in the xlsx (approximated by file — use spreadsheet reader)
                try {
                    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load("downloads/$f");
                    $sh = $ss->getActiveSheet();
                    $highest = $sh->getHighestRow();
                    // Count non-empty cells that look like reg numbers (9 chars) across all rows
                    $count = 0;
                    foreach ($sh->getRowIterator() as $row) {
                        foreach ($row->getCellIterator() as $cell) {
                            $v = trim((string)$cell->getValue());
                            if (strlen($v) === 9 && ctype_alnum($v)) $count++;
                        }
                    }
                    $schoolStudentCounts[$school] = ($schoolStudentCounts[$school] ?? 0) + $count;
                } catch (\Exception $e) {
                    // skip unreadable files
                }
                break;
            }
        }
    }
}
arsort($schoolStudentCounts);
$schoolStudentCounts = array_filter($schoolStudentCounts, fn($v) => $v > 0);

// Per-event student participation: scan downloads for SCHOOL_cleanEventName_date.xlsx
$eventParticipation = []; // eventName => ['count'=>N, 'schools'=>N]
if (file_exists("downloads")) {
    foreach (scandir("downloads") as $f) {
        if (!str_ends_with($f, '.xlsx')) continue;
        foreach ($eventsRaw as $ev) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '_', $ev['name']);
            if (strpos($f, $clean) !== false) {
                // Count students in this file
                try {
                    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load("downloads/$f");
                    $sh = $ss->getActiveSheet();
                    $cnt = 0;
                    foreach ($sh->getRowIterator() as $row) {
                        foreach ($row->getCellIterator() as $cell) {
                            $v = trim((string)$cell->getValue());
                            if (strlen($v) === 9 && ctype_alnum($v)) $cnt++;
                        }
                    }
                    if (!isset($eventParticipation[$ev['name']])) {
                        $eventParticipation[$ev['name']] = ['count'=>0,'schools'=>0];
                    }
                    $eventParticipation[$ev['name']]['count']   += $cnt;
                    $eventParticipation[$ev['name']]['schools'] += 1;
                } catch (\Exception $e) {}
                break;
            }
        }
    }
}
uasort($eventParticipation, fn($a,$b) => $b['count'] - $a['count']);
// Build JS-ready array
$eventParticipationArr = [];
foreach ($eventParticipation as $name => $data) {
    $eventParticipationArr[] = ['name'=>$name,'count'=>$data['count'],'schools'=>$data['schools']];
}

foreach ($historyRaw as $h) {
    $m = (int)date('n', strtotime($h['segregated_on']));
    $segregMonthly[$m]++;
    if (!$lastSegOn) $lastSegOn = $h['segregated_on'];
    $evArr = $h['events'] ? json_decode($h['events'], true) : [];
    foreach ($evArr as $he) {
        if (!empty($he['name'])) $segregatedEventNames[$he['name']] = true;
    }
    $runEventCounts[] = count($evArr);
}

// Define counts before they are used below
$totalEventsRegistered = count($eventsRaw);
$totalSegregationRuns  = count($historyRaw);

// Total students = sum of all school student counts
$totalStudentsAllRuns = array_sum($schoolStudentCounts);
$avgStudentsPerRun    = $totalSegregationRuns > 0 ? round($totalStudentsAllRuns / $totalSegregationRuns) : 0;

if (!empty($eventsRaw)) {
    $lastEventRow = end($eventsRaw);
    $lastEventOn  = $lastEventRow['date'].' ('.$lastEventRow['name'].')';
}

// --- Pending events ---
$pendingEvents = array_filter($eventsRaw, fn($ev) => !isset($segregatedEventNames[$ev['name']]));

$totalPending    = count($pendingEvents);
$avgEventsPerRun = $totalSegregationRuns > 0 ? round(array_sum($runEventCounts) / $totalSegregationRuns, 1) : 0;
$maxEventsInRun  = !empty($runEventCounts) ? max($runEventCounts) : 0;
$minEventsInRun  = !empty($runEventCounts) ? min($runEventCounts) : 0;

// Peak month / dow
$peakEvMonth = array_search(max($eventsMonthly), $eventsMonthly);
$peakDow     = array_search(max($dowCounts), $dowCounts);

// Smart insights
$smartInsights = [];
if ($totalEventsRegistered > 0)
    $smartInsights[] = "📅 <strong>".$monthNamesShort[$peakEvMonth]."</strong> was the most active month (".($eventsMonthly[$peakEvMonth])." events).";
if ($totalPending > 0)
    $smartInsights[] = "⏳ <strong>$totalPending</strong> event(s) registered but not yet segregated.";
if ($totalMultiDay > 0) {
    $pct = round($totalMultiDay / max($totalEventsRegistered,1) * 100);
    $smartInsights[] = "📆 <strong>$pct%</strong> of events are multi-day ($totalMultiDay out of $totalEventsRegistered).";
}
if ($totalSegregationRuns > 0)
    $smartInsights[] = "⚡ System has processed <strong>$totalSegregationRuns</strong> segregation(s) done.";
if (!empty($dowCounts) && max($dowCounts) > 0)
    $smartInsights[] = "📆 Busiest day: <strong>".$dayNames[$peakDow]."</strong> (".max($dowCounts)." events).";
if ($lastSegOn)
    $smartInsights[] = "🕓 Last segregation: <strong>".date('d M Y, h:i A', strtotime($lastSegOn))."</strong>.";
if ($lastEventOn)
    $smartInsights[] = "📋 Most recently registered: <strong>$lastEventOn</strong>.";
$topType = !empty($eventTypeCounts) ? array_search(max($eventTypeCounts), $eventTypeCounts) : null;
if ($topType && $eventTypeCounts[$topType] > 0)
    $smartInsights[] = "🏆 Most common event type: <strong>$topType</strong> ({$eventTypeCounts[$topType]} events).";
if (!empty($teamCounts)) {
    $topTeam = array_key_first($teamCounts);
    $smartInsights[] = "🥇 Top organising team: <strong>$topTeam</strong> ({$teamCounts[$topTeam]} events).";
}
if (!empty($venueCounts)) {
    $topVenue = array_key_first($venueCounts);
    $smartInsights[] = "📍 Most used venue: <strong>$topVenue</strong> ({$venueCounts[$topVenue]} events).";
}
// Most participating school (by student count in downloaded files)
if (!empty($schoolStudentCounts)) {
    $topSchool = array_key_first($schoolStudentCounts);
    $smartInsights[] = "🏫 Most participating school: <strong>$topSchool</strong> (".number_format($schoolStudentCounts[$topSchool])." students attended).";
}
// Most & least attended event (by total students across school files per event date)
// Approximate: most attended = event whose date has most school xlsx files generated
$eventAttendance = [];
if (file_exists("downloads")) {
    foreach (scandir("downloads") as $f) {
        if (!str_ends_with($f, '.xlsx')) continue;
        // filename: SCHOOL_eventname_date.xlsx or SCHOOL_daterange.xlsx
        // Try to match against event names
        foreach ($eventsRaw as $ev) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '_', $ev['name']);
            if (strpos($f, $clean) !== false) {
                $eventAttendance[$ev['name']] = ($eventAttendance[$ev['name']] ?? 0) + 1;
            }
        }
    }
}
if (count($eventAttendance) >= 1) {
    arsort($eventAttendance);
    $mostEv  = array_key_first($eventAttendance);
    $smartInsights[] = "🎯 Most attended event: <strong>$mostEv</strong> ({$eventAttendance[$mostEv]} school file(s) generated).";
    if (count($eventAttendance) >= 2) {
        asort($eventAttendance);
        $leastEv = array_key_first($eventAttendance);
        $smartInsights[] = "📉 Least attended event: <strong>$leastEv</strong> ({$eventAttendance[$leastEv]} school file(s) generated).";
    }
}

/* ================= EVENT REGISTRATION ================= */
if (isset($_POST['register_event'])) {
    $isMultiday = isset($_POST['is_multiday']) && $_POST['is_multiday'] == '1';

    if ($isMultiday) {
        $days = [];
        foreach ($_POST['day_date'] as $i => $dayDate) {
            if (empty($dayDate)) continue;
            $from = $_POST['day_from_hour'][$i].":".$_POST['day_from_minute'][$i]." ".$_POST['day_from_ampm'][$i];
            $to   = $_POST['day_to_hour'][$i].":".$_POST['day_to_minute'][$i]." ".$_POST['day_to_ampm'][$i];
            $days[] = ["date" => $dayDate, "time" => $from." - ".$to];
        }
        usort($days, fn($a,$b) => strcmp($a['date'], $b['date']));

        $stmt = $pdo->prepare("INSERT INTO events (name, venue, organising_team, school, phone_number, event_type, multiday, date, end_date, days) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([
            $_POST['event_name'], $_POST['event_venue'], $_POST['organising_team'] ?? '',
            $_POST['school'] ?? '', $_POST['phone_number'] ?? '',
            $_POST['event_type'] ?? '',
            $days[0]['date'], end($days)['date'], json_encode($days)
        ]);
    } else {
        $time = $_POST['from_hour'].":".$_POST['from_minute']." ".$_POST['from_ampm']
              . " - "
              . $_POST['to_hour'].":".$_POST['to_minute']." ".$_POST['to_ampm'];

        $stmt = $pdo->prepare("INSERT INTO events (name, venue, organising_team, school, phone_number, event_type, multiday, date, time) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmt->execute([
            $_POST['event_name'], $_POST['event_venue'], $_POST['organising_team'] ?? '',
            $_POST['school'] ?? '', $_POST['phone_number'] ?? '',
            $_POST['event_type'] ?? '',
            $_POST['event_date'], $time
        ]);
    }

    header("Location: register_event.php?event_added=1");
    exit();
}

/* ================= DELETE EVENT ================= */
if (isset($_POST['delete_event'])) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([(int)$_POST['event_id']]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ================= DELETE HISTORY RECORD ================= */
if (isset($_POST['delete_history'])) {
    $stmt = $pdo->prepare("DELETE FROM segregation_history WHERE id = ?");
    $stmt->execute([(int)$_POST['history_id']]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ================= SEGREGATION ================= */
if (isset($_POST['segregate_all'])) {

    if (!file_exists("downloads")) mkdir("downloads", 0777, true);

    $createdFiles    = [];
    $schoolEventData = [];
    $eventMeta       = [];

    foreach ($_POST['selected_event'] as $index => $selectedValue) {
        if (empty($selectedValue)) continue;

        $parts     = explode("||", $selectedValue);
        $eventName = trim($parts[0] ?? '');
        $isMulti   = isset($parts[2]) && trim($parts[2]) === 'MULTIDAY';

        $evObj = null;
        foreach ($events as $ev) {
            if ($ev['name'] === $eventName) { $evObj = $ev; break; }
        }
        if (!$evObj) continue;

        if (empty($evObj['days'])) {
            $evObj['days'] = [["date" => $evObj['date'] ?? '', "time" => $evObj['time'] ?? '']];
        }

        if ($isMulti || !empty($evObj['multiday'])) {
            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $evObj['venue'] ?? '',
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => true,
                "days"            => $evObj['days']
            ];

            foreach ($evObj['days'] as $dayIdx => $day) {
                $fileKey = "excel_file_{$index}_{$dayIdx}";
                $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
                if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;

                $dateVal     = $day['date'];
                $spreadsheet = IOFactory::load($tmpFile);
                $allRows     = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

                foreach ($allRows as $row) {
                    if (!is_array($row)) continue;
                    foreach ($row as $cell) {
                        if ($cell === null || is_array($cell)) continue;
                        $regNo = trim((string)$cell);
                        if (strlen($regNo) !== 9) continue;
                        $code  = substr($regNo, 2, 3);
                        foreach ($schoolCodes as $school => $codes) {
                            if (in_array($code, $codes)) {
                                $schoolEventData[$index][$school][$dateVal][$code][] = $regNo;
                            }
                        }
                    }
                }
            }

        } else {
            $date = $evObj['date'] ?? '';
            $time = $evObj['time'] ?? '';

            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $evObj['venue'] ?? '',
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => false,
                "days"            => [["date" => $date, "time" => $time]]
            ];

            $fileKey = "excel_file_{$index}_0";
            $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
            if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;

            $spreadsheet = IOFactory::load($tmpFile);
            $allRows     = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            foreach ($allRows as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $cell) {
                    if ($cell === null || is_array($cell)) continue;
                    $regNo = trim((string)$cell);
                    if (strlen($regNo) !== 9) continue;
                    $code  = substr($regNo, 2, 3);
                    foreach ($schoolCodes as $school => $codes) {
                        if (in_array($code, $codes)) {
                            $schoolEventData[$index][$school][$date][$code][] = $regNo;
                        }
                    }
                }
            }
        }
    }

    /* ===== BUILD ONE EXCEL PER SCHOOL ===== */
    $allSchoolFiles = [];

    foreach ($schoolCodes as $school => $codes) {
        $hasData = false;
        foreach ($eventMeta as $index => $meta) {
            if (!empty($schoolEventData[$index][$school])) { $hasData = true; break; }
        }
        if (!$hasData) continue;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $currentRow  = 1;

        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;

            $sheet->setCellValue('A'.$currentRow, $meta['name']." | ".$meta['venue']);
            $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true)->setSize(13);
            $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
            $currentRow++;

            $dateTimeMap = [];
            foreach (($meta['days'] ?? []) as $day) {
                if (!empty($day['date'])) $dateTimeMap[$day['date']] = $day['time'];
            }

            $collectedDates = array_keys($schoolEventData[$index][$school]);
            sort($collectedDates);

            foreach ($collectedDates as $dateKey) {
                if (empty($schoolEventData[$index][$school][$dateKey])) continue;

                $dateCodes = $schoolEventData[$index][$school][$dateKey];
                $dateStr   = date("d-m-Y", strtotime($dateKey));
                $timeStr   = $dateTimeMap[$dateKey] ?? $meta['days'][0]['time'] ?? '';

                $sheet->setCellValue('A'.$currentRow, $dateStr.($timeStr ? " | ".$timeStr : ""));
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9E1F2');
                $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
                $currentRow++;

                $startColumn = 1;
                $headerRow   = $currentRow;
                $maxRows     = 0;

                foreach ($dateCodes as $code => $regList) {
                    $colLetter = Coordinate::stringFromColumnIndex($startColumn);
                    $sheet->setCellValue($colLetter.$headerRow, $code);
                    $sheet->getStyle($colLetter.$headerRow)->getFont()->setBold(true);
                    $sheet->getStyle($colLetter.$headerRow)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FFF2CC');

                    $rowPointer = $headerRow + 1;
                    foreach ($regList as $reg) {
                        $sheet->setCellValue($colLetter.$rowPointer, $reg);
                        $rowPointer++;
                    }
                    $maxRows = max($maxRows, count($regList));
                    $startColumn++;
                }

                $currentRow = $headerRow + $maxRows + 2;
            }

            $currentRow += 1;
        }

        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $highestCol; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $schoolDates = [];
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;
            foreach (array_keys($schoolEventData[$index][$school]) as $d) {
                $schoolDates[$d] = $d;
            }
        }
        ksort($schoolDates);
        $dateRange = count($schoolDates) > 1
            ? array_key_first($schoolDates)."_to_".array_key_last($schoolDates)
            : (array_key_first($schoolDates) ?? date("Y-m-d"));

        $fileName = $school."_".$dateRange.".xlsx";
        $filePath = "downloads/".$fileName;

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $createdFiles[]   = $fileName;
        $allSchoolFiles[] = $filePath;
    }

    /* ===== CREATE TXT SUMMARY ===== */
    $schoolTotals = []; // school => total students in this run
    foreach ($schoolEventData as $idx => $schoolArr) {
        foreach ($schoolArr as $school => $dateArr) {
            foreach ($dateArr as $code => $regList) {
                if (is_array($regList)) {
                    foreach ($regList as $regs) {
                        $schoolTotals[$school] = ($schoolTotals[$school] ?? 0) + (is_array($regs) ? count($regs) : 0);
                    }
                }
            }
        }
    }
    arsort($schoolTotals);
    $runTotalStudents = array_sum($schoolTotals);

    $runOnDate   = date('d M Y, h:i A');
    $runDateDisp = ($runDateFrom === $runDateTo)
        ? date('d M Y', strtotime($runDateFrom))
        : date('d M Y', strtotime($runDateFrom)).' - '.date('d M Y', strtotime($runDateTo));

    $sep  = "=======================================================";
    $dash = "-------------------------------------------------------";
    $txtLines = [];
    $txtLines[] = $sep;
    $txtLines[] = "  VIT SMART ATTENDANCE SEGREGATOR - Segregation Summary";
    $txtLines[] = "  Generated : $runOnDate";
    $txtLines[] = "  Date Range: $runDateDisp";
    $txtLines[] = $sep;
    $txtLines[] = "";
    $txtLines[] = "--- EVENTS SEGREGATED (".count($eventMeta).") ---";
    foreach ($eventMeta as $meta) {
        $typeTag = $meta['multiday'] ? " [Multi-day]" : " [Single-day]";
        $txtLines[] = "  * ".$meta['name'].$typeTag;
        $txtLines[] = "    Venue: ".$meta['venue'].(!empty($meta['organising_team']) ? " | Faculty: ".$meta['organising_team'] : "");
        foreach (($meta['days'] ?? []) as $d) {
            $dstr = !empty($d['date']) ? date('d M Y', strtotime($d['date'])) : '';
            $tstr = !empty($d['time']) ? " | ".$d['time'] : '';
            $txtLines[] = "    Day: $dstr$tstr";
        }
        $txtLines[] = "";
    }
    $txtLines[] = $dash;
    $txtLines[] = "--- SCHOOL-WISE STUDENT COUNT (".count($schoolTotals)." schools) ---";
    $idx2 = 1;
    foreach ($schoolTotals as $school => $cnt) {
        $txtLines[] = "  ".str_pad($idx2.".", 4).str_pad($school, 12)." : ".number_format($cnt)." students";
        $idx2++;
    }
    $txtLines[] = $dash;
    $txtLines[] = "  TOTAL STUDENTS PROCESSED : ".number_format($runTotalStudents);
    $txtLines[] = $sep;
    $txtLines[] = "  VIT-IST | Office of Innovation, Startup & Technology Transfer";
    $txtLines[] = $sep;

    $summaryFileName = "Segregation_Summary_".$zipLabel.".txt";
    $summaryFilePath = "downloads/".$summaryFileName;
    file_put_contents($summaryFilePath, implode("\n", $txtLines));
    $createdFiles[] = $summaryFileName;

    /* ===== CREATE ONE ZIP (includes TXT summary) ===== */
    $createdZips = [];
    if (!empty($allSchoolFiles)) {
        $allCollectedDates = [];
        foreach ($schoolEventData as $schoolArr) {
            foreach ($schoolArr as $dateArr) {
                foreach (array_keys($dateArr) as $d) {
                    if (!empty($d)) $allCollectedDates[$d] = $d;
                }
            }
        }
        ksort($allCollectedDates);
        $zipDateFrom = !empty($allCollectedDates) ? array_key_first($allCollectedDates) : date("Y-m-d");
        $zipDateTo   = !empty($allCollectedDates) ? array_key_last($allCollectedDates)  : $zipDateFrom;
        $zipLabel    = ($zipDateFrom === $zipDateTo) ? $zipDateFrom : $zipDateFrom."_to_".$zipDateTo;

        $zipFileName = "downloads/all_schools_".$zipLabel."_".date("His").".zip";
        $zip = new ZipArchive();
        if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach (array_unique($allSchoolFiles) as $fp) {
                $zip->addFile($fp, basename($fp));
            }
            // Include the TXT summary in the zip
            $zip->addFile($summaryFilePath, $summaryFileName);
            $zip->close();
        }
        $createdFiles[] = basename($zipFileName);
        $createdZips[]  = basename($zipFileName);
    }

    /* ===== UPDATE HISTORY ===== */
    $runDates = [];
    foreach ($schoolEventData as $schoolArr) {
        foreach ($schoolArr as $dateArr) {
            foreach (array_keys($dateArr) as $d) {
                if (!empty($d)) $runDates[$d] = $d;
            }
        }
    }
    ksort($runDates);

    $runEventSummary = [];
    foreach ($eventMeta as $meta) {
        $dayStrings = array_map(
            fn($d) => ($d['date'] ?? '')." (".($d['time'] ?? '').")",
            $meta['days'] ?? []
        );
        $runEventSummary[] = [
            "name"            => $meta['name'],
            "venue"           => $meta['venue'],
            "organising_team" => $meta['organising_team'] ?? '',
            "multiday"        => $meta['multiday'],
            "days"            => $dayStrings
        ];
    }

    $runDateFrom = !empty($runDates) ? array_key_first($runDates) : date("Y-m-d");
    $runDateTo   = !empty($runDates) ? array_key_last($runDates)  : $runDateFrom;
    $dateLabel   = ($runDateFrom === $runDateTo) ? $runDateFrom : $runDateFrom." to ".$runDateTo;

    $stmt = $pdo->prepare("INSERT INTO segregation_history (run_date_range, date_from, date_to, segregated_on, events, zips) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $dateLabel, $runDateFrom, $runDateTo, date("Y-m-d H:i:s"),
        json_encode($runEventSummary), json_encode($createdZips)
    ]);

    $_SESSION['files'] = $createdFiles;
    header("Location: register_event.php?tab=segregation&segregation=done");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VIT Attendance Segregator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .event-box { border:1px solid #d0d0d0; padding:18px; margin-bottom:14px; border-radius:10px; background:#f9f9f9; }
        .event-box h3 { margin:0 0 12px 0; color:rgb(27,0,93); font-size:16px; }
        .download-links a { color:navy; text-decoration:underline; display:block; margin-bottom:5px; }
        .page { display:none; }
        .page.active { display:block; }

        table { border-collapse:collapse; width:100%; margin-bottom:20px; font-size:13px; }
        th,td { border:1px solid #ddd; padding:8px 10px; text-align:left; }
        th { background:#f0eeff; color:rgb(27,0,93); font-weight:700; }
        tr:hover { background:#fafafa; }

        .nav button, .submit-btn, .modal-content button, .logout-btn {
            background:rgb(27,0,93) !important; color:white !important; border:none !important;
        }
        .nav button:hover, .submit-btn:hover { background:rgb(45,0,140) !important; }
        .btn-delete { background:#c0392b !important; color:white; border:none; padding:5px 12px; border-radius:5px; cursor:pointer; font-size:12px; font-weight:bold; }
        .btn-delete:hover { background:#a93226 !important; }

        .toggle-row { display:flex; align-items:center; gap:10px; margin-bottom:15px; }
        .day-slot { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:10px; background:#fff; }
        .day-slot-header { font-weight:bold; margin-bottom:8px; color:rgb(27,0,93); }
        .remove-day { background:#c0392b !important; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; float:right; }

        .step-label { font-weight:700; color:rgb(27,0,93); font-size:14px; margin-bottom:6px; display:block; }
        .date-filter-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
        .date-filter-row input[type=date] { padding:8px; border-radius:5px; border:1px solid #ccc; }
        .find-btn { padding:8px 18px; background:rgb(27,0,93); color:white; border:none; border-radius:5px; cursor:pointer; font-weight:bold; }
        .find-btn:hover { background:rgb(45,0,140); }

        .day-upload-slot { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .day-upload-slot .day-label { font-weight:700; color:rgb(27,0,93); min-width:130px; font-size:13px; }
        .day-upload-slot .day-time  { color:#555; font-size:12px; min-width:160px; }
        .day-upload-slot input[type=file] { flex:1; min-width:200px; }

.admin-tabs {
    display:flex;
    flex-wrap:wrap; /* ADD THIS */
    gap:4px;        /* small spacing */
    margin-bottom:20px;
    border-bottom:2px solid rgb(27,0,93);
}        .admin-tab  { padding:9px 24px; cursor:pointer; font-weight:700; border:none; background:#f0eeff; color:rgb(27,0,93); border-radius:6px 6px 0 0; margin-right:4px; font-size:14px; }
        .admin-tab.active { background:rgb(27,0,93); color:white; }

        .admin-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
        .admin-controls input[type=text], .admin-controls input[type=date], .admin-controls select { padding:7px; border-radius:5px; border:1px solid #ccc; }
        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; }
        .pagination button { padding:5px 12px; border:1px solid #ccc; border-radius:4px; cursor:pointer; background:#f0f0f0; }
        .pagination button.active-page { background:rgb(27,0,93); color:white; border-color:rgb(27,0,93); }
        .badge-multi { background:rgb(27,0,93); color:white; font-size:11px; padding:2px 7px; border-radius:10px; }
        .no-results { color:#888; font-style:italic; padding:10px 0; }
        .section-count { font-size:13px; color:#555; margin-bottom:8px; }
        .dropdown.show { display:block; }
        /* ---- Analytics ---- */
        .kpi-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:22px; }
        .kpi-card { flex:1; min-width:140px; background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px 14px; text-align:center; box-shadow:0 2px 8px rgba(27,0,93,0.07); }
        .kpi-icon  { font-size:26px; margin-bottom:6px; }
        .kpi-value { font-size:32px; font-weight:800; color:rgb(27,0,93); line-height:1; }
        .kpi-label { font-size:12px; color:#777; margin-top:5px; font-weight:600; letter-spacing:0.5px; }
        .insight-box { background:linear-gradient(135deg,rgb(27,0,93) 0%,rgb(60,0,160) 100%); color:white; border-radius:12px; padding:20px 24px; margin-bottom:22px; }
        .insight-title { font-size:15px; font-weight:800; margin-bottom:12px; letter-spacing:1px; }
        .insight-line  { font-size:13px; margin-bottom:8px; line-height:1.6; opacity:0.95; }
        .insight-line strong { color:#ffe066; }
        .charts-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
        .chart-card { flex:1; min-width:280px; background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px; box-shadow:0 2px 8px rgba(27,0,93,0.07); }
        .chart-card-small { flex:0 0 320px; }
        .chart-title { font-size:13px; font-weight:700; color:rgb(27,0,93); margin-bottom:12px; }
        .analytics-section { background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px; margin-bottom:18px; box-shadow:0 2px 8px rgba(27,0,93,0.07); }

        /* Pill buttons */
        .a-pill { padding:8px 18px; border-radius:30px; border:2px solid rgb(27,0,93); background:white; color:rgb(27,0,93); font-weight:700; font-size:13px; cursor:pointer; transition:all 0.2s; }
        .a-pill.active { background:rgb(27,0,93); color:white; }
        .a-pill:hover:not(.active) { background:#f0eeff; }

        /* New KPI card style (screenshot match) */
        .kpi-card2 { background:white; border:1px solid #e8e8e8; border-radius:14px; padding:22px 16px; text-align:center; box-shadow:0 2px 8px rgba(27,0,93,0.06); transition:box-shadow 0.2s; }
        .kpi-card2:hover { box-shadow:0 4px 16px rgba(27,0,93,0.13); }
        .kpi2-icon { font-size:28px; margin-bottom:8px; }
        .kpi2-val  { font-size:36px; font-weight:800; color:rgb(27,0,93); line-height:1; margin-bottom:6px; }
        .kpi2-label{ font-size:11px; color:#888; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; }

    </style>

    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            if (e.key === 'F12') e.preventDefault();
            if (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) e.preventDefault();
            if (e.ctrlKey && e.key === 'U') e.preventDefault();
        });
    </script>
</head>
<body>

<div class="top-bar">
    <div class="user-menu" onclick="toggleMenu()">
        <img src="vit-logo.png" class="mini-profile">
        <?php echo htmlspecialchars($_SESSION['user']); ?> ▼
    </div>
    <div class="dropdown" id="dropdownMenu">
        <div class="dropdown-profile"><img src="vit-logo.png"></div>
        <a href="index.php?logout=1" class="logout-btn" onclick="return confirmLogout()">Sign out</a>
    </div>
</div>

<div class="main-header">
    <div class="logo-row">
        <img src="vit-logo.png" class="logo">
        <img src="iic-logo.png" class="logo">
    </div>
    <div class="header-text">
        <h2>Office of Innovation, Startup and Technology Transfer (VIT-IST)</h2>
        <h1>SMART ATTENDANCE SEGREGATOR</h1>
    </div>
</div>

<div class="container">
    <div class="nav">
        <button onclick="showPage('register')">Event Registration</button>
        <button onclick="showPage('segregation')">Excel Segregation</button>
        <button onclick="showPage('admin')">Admin Panel</button>
    </div>

    <!-- ==================== EVENT REGISTRATION ==================== -->
    <div id="register" class="page active">
        <h2>Register New Event</h2>
        <form action="" method="POST" autocomplete="off">
            <div class="form-row">
                <label>Event Name</label>
                <input type="text" name="event_name" required>
            </div>
            <div class="form-row">
                <label>Event Venue</label>
                <input type="text" name="event_venue" required>
            </div>
            <div class="form-row">
                <label>Faculty Coordinator <span style="color:red">*</span></label>
                <input type="text" name="organising_team" required>
            </div>
            <div class="form-row">
                <label>School <span style="color:red">*</span></label>
                <input type="text" name="school" required>
            </div>
            <div class="form-row">
                <label>Phone Number <span style="color:red">*</span></label>
                <input type="tel" name="phone_number" required pattern="[0-9]{10}" maxlength="10" placeholder="10-digit mobile number" title="Enter a valid 10-digit phone number">
            </div>
            <div class="form-row">
                <label>Event Type <span style="color:red">*</span></label>
                <select name="event_type" required>
                    <option value="">-- Select Event Type --</option>
                    <option value="Expert Talk">1. Expert Talk</option>
                    <option value="Mentoring Session">2. Mentoring Session</option>
                    <option value="Workshop">3. Workshop</option>
                    <option value="Seminar">4. Seminar</option>
                    <option value="Boot Camp">5. Boot Camp</option>
                    <option value="Expo">6. Expo</option>
                    <option value="Demo Day / Competition">7. Demo Day / Competition</option>
                    <option value="Tech Fest / Hackathon / Ideathon">8. Tech Fest / Hackathon / Ideathon</option>
                </select>
            </div>

            <div class="toggle-row">
                <label><strong>Multi-day Event?</strong></label>
                <input type="checkbox" id="multiday_toggle" name="is_multiday" value="1" onchange="toggleMultiday()">
            </div>

            <div id="singleday_fields">
                <div class="form-row">
                    <label>Event Date</label>
                    <input type="date" name="event_date">
                </div>
                <div class="form-row">
                    <label>Event Timing</label>
                    <div class="time-group">
                        <div>
                            <span>From</span><br>
                            <select name="from_hour"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="from_minute"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="from_ampm"><option>AM</option><option>PM</option></select>
                        </div>
                        <div>
                            <span>To</span><br>
                            <select name="to_hour"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="to_minute"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="to_ampm"><option>AM</option><option>PM</option></select>
                        </div>
                    </div>
                </div>
            </div>

            <div id="multiday_fields" style="display:none;">
                <div id="day_slots_container"></div>
                <button type="button" onclick="addDaySlot()" style="margin-bottom:12px;padding:7px 18px;background:rgb(27,0,93);color:white;border:none;border-radius:5px;cursor:pointer;">+ Add Day</button>
            </div>

            <button type="submit" name="register_event" class="submit-btn" onclick="return confirmAddEvent()">Add Event</button>
        </form>
    </div>

    <!-- ==================== EXCEL SEGREGATION ==================== -->
    <div id="segregation" class="page">
        <h2>Excel Segregation</h2>

        <span class="step-label">Step 1 — Select Date Range</span>
        <div class="date-filter-row">
            <input type="date" id="filter_date_from" required>
            <span>to</span>
            <input type="date" id="filter_date_to" required>
            <button class="find-btn" onclick="filterEventsByRange()">🔍 Find Events</button>
        </div>

        <div id="num_events_row" style="display:none; margin-bottom:18px;">
            <span class="step-label">Step 2 — Number of Events to Segregate</span>
            <select id="num_events" style="padding:8px;border-radius:5px;border:1px solid #ccc;min-width:220px;">
                <option value="">-- Select --</option>
            </select>
        </div>

        <form id="segregate_all_form" method="POST" enctype="multipart/form-data">
            <div id="event_toggles"></div>
            <div id="segregate_btn_wrap" style="display:none;">
                <button type="submit" name="segregate_all" class="submit-btn">⚡ Segregate All Events</button>
            </div>
        </form>

        <div id="segregation_results" class="download-links" style="margin-top:16px;">
            <?php
            if (isset($_SESSION['files'])) {
                echo "<hr><h3 style='color:green;margin-bottom:14px;'>✅ Segregation Completed Successfully</h3>";

                $zipFiles     = array_filter($_SESSION['files'], fn($f) => str_ends_with($f, '.zip'));
                $summaryFiles = array_filter($_SESSION['files'], fn($f) => str_ends_with($f, '.txt'));
                $schoolFiles  = array_filter($_SESSION['files'], fn($f) =>
                    !str_ends_with($f, '.zip') && !str_ends_with($f, '.txt'));

                // ---- Summary TXT link ----
                foreach ($summaryFiles as $file) {
                    $sf = htmlspecialchars($file);
                    echo "
                    <div style='background:#f0eeff;border:2px solid rgb(27,0,93);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;'>
                        <div>
                            <div style='font-weight:700;color:rgb(27,0,93);font-size:15px;'>📄 Segregation Summary Report</div>
                            <div style='font-size:12px;color:#666;margin-top:3px;'>Plain text summary of this segregation run</div>
                        </div>
                        <a href='downloads/$sf' download style='padding:10px 22px;background:rgb(27,0,93);color:white;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px;'>⬇ Download Summary (.txt)</a>
                    </div>";
                }

                // ---- ZIP download ----
                foreach ($zipFiles as $file) {
                    $sf = htmlspecialchars($file);
                    echo "<div style='margin-bottom:14px;'><strong>📦 Download All Schools (ZIP — includes summary):</strong><br>
                        <a href='downloads/$sf' target='_blank' style='color:navy;'>⬇ $sf</a></div>";
                }

                // ---- Individual school files ----
                if (!empty($schoolFiles)) {
                    echo "<details style='margin-top:4px;'><summary style='cursor:pointer;font-weight:700;color:rgb(27,0,93);padding:6px 0;'>📁 Individual School Files (".count($schoolFiles).")</summary><div style='margin-top:8px;'>";
                    foreach ($schoolFiles as $file) {
                        $sf = htmlspecialchars($file);
                        echo "<p style='margin:4px 0 4px 12px;'>⬇ <a href='downloads/$sf' target='_blank'>$sf</a></p>";
                    }
                    echo "</div></details>";
                }

                unset($_SESSION['files']);
            }
            ?>
        </div>
    </div>

    <!-- ==================== ADMIN PANEL ==================== -->
    <div id="admin" class="page">
        <h2>Admin Panel</h2>

        <div class="admin-tabs">
            <button class="admin-tab active" onclick="switchAdminTab('events')">📋 Events</button>
            <button class="admin-tab" onclick="switchAdminTab('history')">🕓 Segregation History</button>
            <button class="admin-tab" onclick="switchAdminTab('analytics')">📊 Analytics</button>
        </div>

        <div id="admin_events_tab">
            <div class="admin-controls">
                <input type="text" id="ev_search" placeholder="Search event name / venue..." oninput="renderEventsTable()">
                <input type="date" id="ev_date_from" onchange="renderEventsTable()">
                <span>to</span>
                <input type="date" id="ev_date_to" onchange="renderEventsTable()">
                <select id="ev_type_filter" onchange="renderEventsTable()">
                    <option value="">All Event Types</option>
                    <option>Expert Talk</option>
                    <option>Mentoring Session</option>
                    <option>Workshop</option>
                    <option>Seminar</option>
                    <option>Boot Camp</option>
                    <option>Expo</option>
                    <option>Demo Day / Competition</option>
                    <option>Tech Fest / Hackathon / Ideathon</option>
                </select>
                <select id="ev_sort" onchange="renderEventsTable()">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="name_asc">Name A–Z</option>
                    <option value="name_desc">Name Z–A</option>
                </select>
                <button onclick="clearEvFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="events_table_container"></div>
            <div class="pagination" id="events_pagination"></div>
        </div>

        <div id="admin_history_tab" style="display:none;">
            <div class="admin-controls">
                <input type="text"  id="admin_search"    placeholder="Search event name / venue..." oninput="renderAdminTable()">
                <input type="date"  id="admin_date_from" onchange="renderAdminTable()">
                <span>to</span>
                <input type="date"  id="admin_date_to"   onchange="renderAdminTable()">
                <select id="admin_sort" onchange="renderAdminTable()">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="event_date_asc">Event Date ↑</option>
                    <option value="event_date_desc">Event Date ↓</option>
                </select>
                <button onclick="clearAdminFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="admin_table_container"></div>
            <div class="pagination" id="admin_pagination"></div>
        </div>

        <!-- ANALYTICS SUB-TAB -->
        <div id="admin_analytics_tab" style="display:none;">

            <!-- ===== TIME FILTER PILLS + EVENT TYPE + DOWNLOAD ===== -->
            <div style="background:#fff;border:1px solid #e8e8e8;border-radius:14px;padding:16px 20px;margin-bottom:18px;">
                <!-- Row 1: Time pills + showing label -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;" id="time_pill_row">
                        <button class="a-pill active" id="pill_alltime" onclick="setTimePill('alltime')">🗓️ All Time</button>
                        <button class="a-pill" id="pill_year"    onclick="setTimePill('year')">🗓️ Year</button>
                        <button class="a-pill" id="pill_month"   onclick="setTimePill('month')">🗓️ Month</button>
                        <button class="a-pill" id="pill_mrange"  onclick="setTimePill('mrange')">🗓️ Month Range</button>
                    </div>
                    <div style="background:#f0eeff;color:rgb(27,0,93);font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;" id="showing_label">Showing: All Time</div>
                </div>
                <!-- Row 2: Dynamic time inputs (hidden by default, shown per pill) -->
                <div id="time_inputs_alltime" style="display:none;"></div>
                <div id="time_inputs_year" style="display:none;gap:10px;align-items:center;">
                    <label style="font-weight:600;font-size:13px;">Year:</label>
                    <select id="filter_year" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                </div>
                <div id="time_inputs_month" style="display:none;gap:10px;align-items:center;">
                    <label style="font-weight:600;font-size:13px;">Month:</label>
                    <select id="filter_month_year" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                    <select id="filter_month_month" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php $mn=['January','February','March','April','May','June','July','August','September','October','November','December'];
                        foreach($mn as $mi=>$ml) { $v=str_pad($mi+1,2,'0',STR_PAD_LEFT); $sel=($mi+1==(int)date('n'))?'selected':''; echo "<option value='$v' $sel>$ml</option>"; } ?>
                    </select>
                </div>
                <div id="time_inputs_mrange" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="font-weight:600;font-size:13px;">From:</label>
                    <select id="filter_mrange_from_year" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                    <select id="filter_mrange_from_month" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php foreach($mn as $mi=>$ml){$v=str_pad($mi+1,2,'0',STR_PAD_LEFT);echo "<option value='$v'>$ml</option>";}?>
                    </select>
                    <label style="font-weight:600;font-size:13px;">To:</label>
                    <select id="filter_mrange_to_year" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                    <select id="filter_mrange_to_month" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php foreach($mn as $mi=>$ml){$v=str_pad($mi+1,2,'0',STR_PAD_LEFT);$sel=($mi+1==(int)date('n'))?'selected':'';echo "<option value='$v' $sel>$ml</option>";}?>
                    </select>
                </div>
                <!-- Row 3: Event type filter + download -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0;">
                    <label style="font-weight:600;font-size:13px;color:rgb(27,0,93);">Event Type:</label>
                    <select id="analytics_type_filter" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;min-width:210px;">
                        <option value="all">All Event Types</option>
                        <option>Expert Talk</option><option>Mentoring Session</option>
                        <option>Workshop</option><option>Seminar</option>
                        <option>Boot Camp</option><option>Expo</option>
                        <option>Demo Day / Competition</option>
                        <option>Tech Fest / Hackathon / Ideathon</option>
                    </select>
                    <button onclick="downloadAnalyticsTXT()" style="margin-left:auto;padding:8px 18px;background:rgb(27,0,93);color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">⬇ Download Analytics Report (.txt)</button>
                </div>
            </div>

            <!-- ===== KPI CARDS ===== -->
            <div style="display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:12px;margin-bottom:18px;" id="kpi_grid">
                <div class="kpi-card2"><div class="kpi2-icon">📋</div><div class="kpi2-val" id="kpi_total_events"><?= $totalEventsRegistered ?></div><div class="kpi2-label">EVENTS REGISTERED</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">⚡</div><div class="kpi2-val" id="kpi_seg_runs"><?= $totalSegregationRuns ?></div><div class="kpi2-label">SEGREGATIONS DONE</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">🎓</div><div class="kpi2-val" id="kpi_students"><?= number_format($totalStudentsAllRuns) ?></div><div class="kpi2-label">STUDENTS PROCESSED</div></div>
                <div class="kpi-card2" style="border-color:#e74c3c;"><div class="kpi2-icon">⚠️</div><div class="kpi2-val" id="kpi_pending" style="color:#e74c3c;"><?= $totalPending ?></div><div class="kpi2-label" style="color:#e74c3c;">PENDING</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">📅</div><div class="kpi2-val" id="kpi_single"><?= $totalSingleDay ?></div><div class="kpi2-label">SINGLE-DAY EVENTS</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">🗓️</div><div class="kpi2-val" id="kpi_multi"><?= $totalMultiDay ?></div><div class="kpi2-label">MULTI-DAY EVENTS</div></div>
            </div>

            <!-- ===== SMART INSIGHTS ===== -->
            <div class="insight-box" id="insight_box_dynamic">
                <div class="insight-title">🧠 Smart Insights</div>
                <div id="insight_lines_dynamic">
                <?php
                // Build all insight data as PHP for JS to use
                ?>
                </div>
            </div>

            <!-- ===== ROW 1: Pending Events + Segregation Stats ===== -->
            <div class="charts-row" style="align-items:stretch;">

                <!-- Pending Events card -->
                <div class="chart-card" style="flex:1.2;min-width:300px;">
                    <div class="chart-title" style="color:#e74c3c;">⚠️ Pending Events (Registered but Never Segregated)</div>
                    <?php if (empty($pendingEvents)): ?>
                        <p style="color:green;font-weight:bold;margin-top:12px;">✅ All events have been segregated.</p>
                    <?php else: foreach ($pendingEvents as $pev):
                        $pdate = $pev['multiday']
                            ? date('d M Y',strtotime($pev['date'])).' – '.date('d M Y',strtotime($pev['end_date']??$pev['date']))
                            : date('d M Y',strtotime($pev['date']));
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid #f5dada;border-radius:8px;margin-bottom:8px;background:#fff9f9;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:16px;">📋</span>
                                <span style="font-weight:700;color:#222;"><?= htmlspecialchars($pev['name']) ?></span>
                                <span style="color:#888;font-size:12px;">— <?= htmlspecialchars($pev['venue']) ?></span>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;">
                            <span style="font-size:12px;color:#555;"><?= $pdate ?></span>
                            <span style="background:#e74c3c;color:white;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;">Pending</span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Segregation Stats table -->
                <div class="chart-card" style="flex:1;min-width:260px;">
                    <div class="chart-title">📊 Segregation Stats</div>
                    <table style="margin-top:8px;">
                        <tr><th>Metric</th><th>Value</th></tr>
                        <tr><td>Total Segregations Done</td><td><strong><?= $totalSegregationRuns ?></strong></td></tr>
                        <tr><td>Total Students</td><td><strong><?= number_format($totalStudentsAllRuns) ?></strong></td></tr>
                        <tr><td>Avg Students / Segregation</td><td><strong><?= number_format($avgStudentsPerRun) ?></strong></td></tr>
                        <tr><td>Busiest Day of Week</td><td><strong><?= !empty($dowCounts) && max($dowCounts) > 0 ? $dayNames[array_search(max($dowCounts),$dowCounts)] : '–' ?></strong></td></tr>
                        <tr><td>Last Segregation</td><td><strong><?= $lastSegOn ? date('d-m-Y g:i A', strtotime($lastSegOn)) : '–' ?></strong></td></tr>
                        <tr><td>Last Event Registered</td><td><strong><?= htmlspecialchars($lastEventOn ?? '–') ?></strong></td></tr>
                    </table>
                </div>
            </div>

            <!-- ===== ROW 2: Monthly charts ===== -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-title">📅 Events Registered — Monthly</div>
                    <canvas id="chartEventsMonthly" height="220"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title">⚡ Segregations Done — Monthly</div>
                    <canvas id="chartSegregMonthly" height="220"></canvas>
                </div>
            </div>

            <!-- ===== ROW 3: Trend line + Event Type Split donut ===== -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-title">📈 Registered vs Segregated (Monthly Trend)</div>
                    <canvas id="chartComparison" height="220"></canvas>
                </div>
                <div class="chart-card chart-card-small">
                    <div class="chart-title">🔵 Event Type Split</div>
                    <canvas id="chartTypeSplit" height="220"></canvas>
                </div>
            </div>

            <!-- ===== ROW 4: Busiest Days + Heatmap ===== -->
            <div class="charts-row" style="align-items:stretch;">
                <div class="chart-card" style="flex:0 0 320px;">
                    <div class="chart-title">📅 Busiest Days of the Week</div>
                    <canvas id="chartDOW" height="280"></canvas>
                </div>
                <div class="chart-card" style="flex:1;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <div class="chart-title" style="margin-bottom:0;">📅 Event Density Heatmap — <span id="heatmapYearLabel"><?= date('Y') ?></span></div>
                        <div style="display:flex;gap:6px;">
                            <button onclick="heatmapPrevYear()" style="padding:3px 10px;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#f5f5f5;">◀</button>
                            <button onclick="heatmapNextYear()" style="padding:3px 10px;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#f5f5f5;">▶</button>
                        </div>
                    </div>
                    <div id="heatmapContainer" style="overflow-x:auto;"></div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:12px;color:#888;">
                        <span>Less</span>
                        <div style="width:12px;height:12px;border-radius:2px;background:#edf2ff;border:1px solid #ddd;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#9b9fd4;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#6366c1;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#1b005d;"></div>
                        <span>More</span>
                    </div>
                </div>
            </div>

            <!-- ===== ROW 5: School-wise Attendance ===== -->
            <div class="chart-card" style="margin-bottom:18px;">
                <div class="chart-title">🏫 School-wise Attendance Distribution</div>
                <?php if (empty($schoolStudentCounts)): ?>
                    <p style="color:#888;font-style:italic;padding:14px 0;">No segregation data available yet.</p>
                <?php else:
                    $maxStudents = max($schoolStudentCounts);
                    $schoolColors = ['#6366c1','#3bbfd8','#f4a03a','#222b5e','#1b005d','#27ae60','#56cfe1','#e67e22','#00b4d8','#e74c3c','#8e44ad','#2ecc71','#f39c12','#16a085','#c0392b','#2980b9'];
                    $ci = 0;
                    foreach ($schoolStudentCounts as $school => $count):
                        $barPct = $maxStudents > 0 ? round($count / $maxStudents * 100) : 0;
                        $color  = $schoolColors[$ci % count($schoolColors)]; $ci++;
                ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <div style="min-width:70px;font-weight:700;font-size:13px;color:#222;"><?= htmlspecialchars($school) ?></div>
                    <div style="flex:1;background:#f0f0f0;border-radius:6px;height:28px;position:relative;overflow:visible;">
                        <div style="width:<?= $barPct ?>%;background:<?= $color ?>;height:100%;border-radius:6px;display:flex;align-items:center;justify-content:flex-end;padding-right:8px;min-width:40px;transition:width 0.3s;">
                            <span style="color:white;font-size:12px;font-weight:700;white-space:nowrap;"><?= number_format($count) ?></span>
                        </div>
                    </div>
                    <div style="min-width:50px;text-align:right;font-weight:700;font-size:13px;color:#444;"><?= number_format($count) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- ===== ROW 6: Event Type Breakdown ===== -->
            <div class="charts-row">
                <div class="chart-card chart-card-small">
                    <div class="chart-title">🏷️ Events by Type</div>
                    <canvas id="chartEventTypeSplit" height="260"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title">📊 Event Type Breakdown</div>
                    <div id="event_type_table_container"></div>
                </div>
            </div>

            <!-- ===== ROW 7: Venue + Team leaderboard cards ===== -->
            <div class="charts-row" style="align-items:stretch;">

                <!-- Venue Utilisation -->
                <div class="chart-card" style="flex:1;">
                    <div class="chart-title">🏛️ Venue Utilisation (Top 10)</div>
                    <div id="venueLeaderboard" style="margin-top:10px;">
                    <?php
                    $rankColors = ['#ffc107','#9e9e9e','#cd7f32'];
                    $rankEmojis = ['🥇','🥈','🥉'];
                    $barColors  = ['#ffc107','#9e9e9e','#cd7f32','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d'];
                    $vi = 0;
                    foreach (array_slice($venueCounts, 0, 10, true) as $venue => $cnt):
                        $maxV   = max(array_values($venueCounts) ?: [1]);
                        $barPct = round($cnt / $maxV * 100);
                        $barCol = $barColors[$vi] ?? '#1b005d';
                        $rank   = $vi + 1;
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f5f5f5;">
                        <div style="min-width:28px;text-align:center;">
                            <?php if ($vi < 3): ?>
                                <span style="font-size:20px;"><?= $rankEmojis[$vi] ?></span>
                            <?php else: ?>
                                <span style="font-weight:700;font-size:14px;color:#555;"><?= $rank ?>.</span>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;font-size:13px;color:#222;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($venue) ?></div>
                            <div style="background:#f0f0f0;border-radius:6px;height:10px;">
                                <div style="width:<?= $barPct ?>%;background:<?= $barCol ?>;height:10px;border-radius:6px;min-width:8px;"></div>
                            </div>
                        </div>
                        <div style="min-width:70px;text-align:right;font-size:12px;color:#555;white-space:nowrap;"><?= $cnt ?> event<?= $cnt>1?'s':'' ?></div>
                    </div>
                    <?php $vi++; endforeach; ?>
                    <?php if (empty($venueCounts)): ?><p style="color:#888;font-style:italic;">No venue data yet.</p><?php endif; ?>
                    </div>
                </div>

                <!-- Organising Team Leaderboard -->
                <div class="chart-card" style="flex:1;">
                    <div class="chart-title">👥 Organising Team Leaderboard (Top 10)</div>
                    <div id="teamLeaderboard" style="margin-top:10px;">
                    <?php
                    $ti = 0;
                    foreach (array_slice($teamCounts, 0, 10, true) as $team => $cnt):
                        $maxT   = max(array_values($teamCounts) ?: [1]);
                        $barPct = round($cnt / $maxT * 100);
                        $barCol = $barColors[$ti] ?? '#1b005d';
                        $rank   = $ti + 1;
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f5f5f5;">
                        <div style="min-width:28px;text-align:center;">
                            <?php if ($ti < 3): ?>
                                <span style="font-size:20px;"><?= $rankEmojis[$ti] ?></span>
                            <?php else: ?>
                                <span style="font-weight:700;font-size:14px;color:#555;"><?= $rank ?>.</span>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;font-size:13px;color:#222;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($team) ?></div>
                            <div style="background:#f0f0f0;border-radius:6px;height:10px;">
                                <div style="width:<?= $barPct ?>%;background:<?= $barCol ?>;height:10px;border-radius:6px;min-width:8px;"></div>
                            </div>
                        </div>
                        <div style="min-width:70px;text-align:right;font-size:12px;color:#555;white-space:nowrap;"><?= $cnt ?> event<?= $cnt>1?'s':'' ?></div>
                    </div>
                    <?php $ti++; endforeach; ?>
                    <?php if (empty($teamCounts)): ?><p style="color:#888;font-style:italic;">No team data yet.</p><?php endif; ?>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- ==================== MODAL ==================== -->
    <div id="rulesModal" class="modal">
        <div class="modal-content">
            <h2>WELCOME TO SMART ATTENDANCE SEGREGATOR</h2>
            <h2>Please read this page!</h2>
            <ul>
                <li>Event Name must be unique.</li>
                <li>Event details must be accurate.</li>
                <li>For multi-day events, add one slot per day with its own date and time.</li>
                <li>For multi-day segregation, each day gets its own Excel upload box.</li>
            </ul>
            <button id="closeModal">I Understand</button>
        </div>
    </div>
</div>

<script>
/* ==================== HELPERS ==================== */
function showPage(pageId) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById(pageId).classList.add('active');
}
function toggleMenu() { document.getElementById("dropdownMenu").classList.toggle("show"); }
window.addEventListener("click", e => {
    if (!e.target.closest('.user-menu')) document.getElementById("dropdownMenu").classList.remove("show");
});
function confirmLogout() { return confirm("Are you sure you want to sign out?"); }

/* ==================== MODAL ==================== */
const modal = document.getElementById('rulesModal');
document.getElementById('closeModal').addEventListener('click', () => { modal.style.display = 'none'; });

/* ==================== MULTI-DAY REGISTRATION ==================== */
let dayCount = 0;
function makeTimeSelects(prefix, idx) {
    let h = `<select name="${prefix}_hour[${idx}]">`, m = `<select name="${prefix}_minute[${idx}]">`, ap = `<select name="${prefix}_ampm[${idx}]">`;
    for (let i = 1; i <= 12; i++) h += `<option>${i}</option>`;
    h += '</select>';
    for (let i = 0; i <= 59; i++) { let mm = String(i).padStart(2,'0'); m += `<option>${mm}</option>`; }
    m += '</select>';
    ap += '<option>AM</option><option>PM</option></select>';
    return h + ' ' + m + ' ' + ap;
}
function addDaySlot() {
    const container = document.getElementById('day_slots_container');
    const idx = dayCount++;
    const div = document.createElement('div');
    div.classList.add('day-slot');
    div.id = 'day_slot_' + idx;
    div.innerHTML = `
        <div class="day-slot-header">Day ${idx + 1}
            <button type="button" class="remove-day" onclick="removeDaySlot(${idx})">✕ Remove</button>
        </div>
        <div class="form-row">
            <label>Date</label>
            <input type="date" name="day_date[${idx}]" required>
        </div>
        <div class="form-row">
            <label>Timing</label>
            <div class="time-group">
                <div><span>From</span><br>${makeTimeSelects('day_from', idx)}</div>
                <div><span>To</span><br>${makeTimeSelects('day_to', idx)}</div>
            </div>
        </div>`;
    container.appendChild(div);
}
function removeDaySlot(idx) {
    const el = document.getElementById('day_slot_' + idx);
    if (el) el.remove();
}
function toggleMultiday() {
    const checked = document.getElementById('multiday_toggle').checked;
    document.getElementById('singleday_fields').style.display = checked ? 'none' : 'block';
    document.getElementById('multiday_fields').style.display  = checked ? 'block' : 'none';
    document.querySelectorAll('#singleday_fields input[type=date]').forEach(el => { el.required = !checked; });
    if (checked && dayCount === 0) addDaySlot();
}

/* ==================== SEGREGATION ==================== */
const eventsData      = <?php echo json_encode($events); ?>;
const filterDateFrom  = document.getElementById('filter_date_from');
const filterDateTo    = document.getElementById('filter_date_to');
const numEventsSelect = document.getElementById('num_events');
const eventToggles    = document.getElementById('event_toggles');
const segregResults   = document.getElementById('segregation_results');

let availableEvents = [];

function filterEventsByRange() {
    const from = filterDateFrom.value;
    const to   = filterDateTo.value;

    if (!from) { alert('Please select a start date.'); return; }
    if (!to)   { alert('Please select an end date.'); return; }
    if (to < from) { alert('End date cannot be before start date.'); return; }

    availableEvents = eventsData.filter(ev => {
        const evStart = ev.date;
        const evEnd   = ev.end_date || ev.date;
        return evStart <= to && evEnd >= from;
    });

    eventToggles.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    segregResults.innerHTML = '';

    if (availableEvents.length === 0) {
        segregResults.innerHTML = '<p style="color:#c0392b;">⚠ No events found in this date range.</p>';
        document.getElementById('num_events_row').style.display = 'none';
        return;
    }

    segregResults.innerHTML = `<p style="color:green;">✅ ${availableEvents.length} event(s) found in range.</p>`;
    let opts = '';
    for (let i = 1; i <= availableEvents.length; i++) opts += `<option value="${i}">${i}</option>`;
    numEventsSelect.innerHTML = '<option value="">-- Select --</option>' + opts;
    numEventsSelect.value     = '';
    document.getElementById('num_events_row').style.display = 'block';
}

function buildFileUploadSlots(evObj, eventIdx) {
    if (evObj.multiday && evObj.days && evObj.days.length > 0) {
        let html = `<div style="margin-top:10px;"><strong style="color:rgb(27,0,93);">📅 Upload Attendance Excel — One per Day:</strong></div>`;
        evObj.days.forEach((day, dayIdx) => {
            const dateFormatted = day.date
                ? new Date(day.date + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'})
                : '';
            html += `<div class="day-upload-slot">
                <span class="day-label">📆 Day ${dayIdx+1}: ${dateFormatted}</span>
                <span class="day-time">${day.time || ''}</span>
                <input type="file" name="excel_file_${eventIdx}_${dayIdx}" accept=".xlsx,.xls" required>
            </div>`;
        });
        return html;
    } else {
        return `<div class="form-row" style="margin-top:10px;">
            <label>📄 Upload Attendance Excel</label>
            <input type="file" name="excel_file_${eventIdx}_0" accept=".xlsx,.xls" required>
        </div>`;
    }
}

numEventsSelect.addEventListener('change', function () {
    const count = parseInt(this.value);
    eventToggles.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    if (!count || availableEvents.length === 0) return;

    for (let i = 0; i < count; i++) {
        const div = document.createElement('div');
        div.classList.add('event-box');

        let options = '<option value="">-- Select Event --</option>';
        availableEvents.forEach(ev => {
            let val, label;
            if (ev.multiday) {
                const dStart = ev.date     ? new Date(ev.date+'T00:00:00').toLocaleDateString('en-IN') : '';
                const dEnd   = ev.end_date ? new Date(ev.end_date+'T00:00:00').toLocaleDateString('en-IN') : '';
                val   = `${ev.name}||${ev.venue}||MULTIDAY`;
                label = `📆 ${ev.name} (Multi-day: ${dStart} – ${dEnd} | ${ev.venue})`;
            } else {
                val   = `${ev.name}||${ev.venue}||${ev.date}||${ev.time}`;
                label = `📅 ${ev.name} (${ev.date} | ${ev.time} | ${ev.venue})`;
            }
            options += `<option value="${val}">${label}</option>`;
        });

        div.innerHTML = `
            <h3>Event ${i + 1}</h3>
            <div class="form-row">
                <label>Select Event</label>
                <select name="selected_event[]" class="event-name-select" data-idx="${i}">
                    ${options}
                </select>
            </div>
            <div id="event_upload_${i}"></div>`;

        eventToggles.appendChild(div);
    }

    document.getElementById('segregate_btn_wrap').style.display = 'block';

    document.querySelectorAll('.event-name-select').forEach(sel => {
        sel.addEventListener('change', function () {
            const idx = parseInt(this.dataset.idx);
            const val = this.value;
            const uploadContainer = document.getElementById('event_upload_' + idx);
            uploadContainer.innerHTML = '';
            if (!val) return;
            const evName = val.split('||')[0];
            const evObj  = availableEvents.find(e => e.name === evName);
            if (!evObj) return;
            uploadContainer.innerHTML = buildFileUploadSlots(evObj, idx);
        });
    });
});

/* ==================== ADMIN PANEL ==================== */
const allHistoryData = <?php
    // Build a lookup: event name => {school, phone_number}
    $eventLookup = [];
    foreach ($eventsRaw as $ev) {
        $eventLookup[$ev['name']] = [
            'school'       => $ev['school']       ?? '',
            'phone_number' => $ev['phone_number']  ?? '',
        ];
    }

    $adminHistory = [];
    foreach ($history as $record) {
        $eventSummaries = [];
        $teamsList      = [];
        foreach (($record['events'] ?? []) as $ev) {
            if (!is_array($ev)) continue;
            $team  = $ev['organising_team'] ?? '';
            $ename = $ev['name'] ?? '';
            $days  = isset($ev['days']) ? implode('; ', (array)$ev['days']) : '';
            $eventSummaries[] = $ename." | ".($ev['venue'] ?? '').($days ? " | ".$days : "");
            // Build team string with school + phone in brackets
            $extra = '';
            if (isset($eventLookup[$ename])) {
                $sc = $eventLookup[$ename]['school'];
                $ph = $eventLookup[$ename]['phone_number'];
                $parts = array_filter([$sc, $ph ? '📞 '.$ph : '']);
                if ($parts) $extra = ' ('.implode(' | ', $parts).')';
            }
            if ($team || $extra) $teamsList[] = ($team ?: '–').$extra;
        }
        $adminHistory[] = [
            "id"               => $record['id'],
            "date_range"       => $record['run_date_range'] ?? '',
            "date_from"        => $record['date_from'] ?? '',
            "date_to"          => $record['date_to'] ?? '',
            "segregated_on"    => $record['segregated_on'] ?? '',
            "events_text"      => implode("\n", $eventSummaries),
            "event_count"      => count($record['events'] ?? []),
            "organising_teams" => implode("<br>", array_unique($teamsList)),
            "zips"             => $record['zips'] ?? []
        ];
    }
    echo json_encode($adminHistory);
?>;

const allEventsData = <?php echo json_encode($events); ?>;
const PAGE_SIZE = 10;
let adminCurrentPage  = 1;
let eventsCurrentPage = 1;

function switchAdminTab(tab) {
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('admin_events_tab').style.display     = tab === 'events'    ? 'block' : 'none';
    document.getElementById('admin_history_tab').style.display    = tab === 'history'   ? 'block' : 'none';
    document.getElementById('admin_analytics_tab').style.display  = tab === 'analytics' ? 'block' : 'none';
    document.querySelectorAll('.admin-tab')[0].classList.toggle('active', tab === 'events');
    document.querySelectorAll('.admin-tab')[1].classList.toggle('active', tab === 'history');
    document.querySelectorAll('.admin-tab')[2].classList.toggle('active', tab === 'analytics');
    if (tab === 'analytics') setTimeout(initCharts, 50);
}

function getFilteredEvents() {
    const search   = document.getElementById('ev_search').value.toLowerCase();
    const dateFrom = document.getElementById('ev_date_from').value;
    const dateTo   = document.getElementById('ev_date_to').value;
    const typeFilter = document.getElementById('ev_type_filter').value;
    const sort     = document.getElementById('ev_sort').value;

    let data = allEventsData.filter(ev => {
        const matchText = !search || ev.name.toLowerCase().includes(search) || ev.venue.toLowerCase().includes(search);
        const evEnd     = ev.end_date || ev.date;
        const matchFrom = !dateFrom || evEnd >= dateFrom;
        const matchTo   = !dateTo   || ev.date <= dateTo;
        const matchType = !typeFilter || ev.event_type === typeFilter;
        return matchText && matchFrom && matchTo && matchType;
    });

    data.sort((a, b) => {
        if (sort === 'newest')    return b.date.localeCompare(a.date);
        if (sort === 'oldest')    return a.date.localeCompare(b.date);
        if (sort === 'name_asc')  return a.name.localeCompare(b.name);
        if (sort === 'name_desc') return b.name.localeCompare(a.name);
        return 0;
    });
    return data;
}

function formatDate(ymd) {
    if (!ymd) return '';
    const p = ymd.split('-');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : ymd;
}

function formatDateTime(dt) {
    if (!dt) return '–';
    // dt format: "2025-03-05 14:32:00"
    const parts = dt.split(' ');
    const datePart = formatDate(parts[0]);
    if (!parts[1]) return datePart;
    // Convert HH:MM:SS to h:MM AM/PM
    const t = parts[1].split(':');
    let h = parseInt(t[0]), m = t[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${datePart} ${h}:${m} ${ampm}`;
}

function renderEventsTable() { eventsCurrentPage = 1; renderEventsPage(); }

function renderEventsPage() {
    const data  = getFilteredEvents();
    const total = data.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start = (eventsCurrentPage - 1) * PAGE_SIZE;
    const slice = data.slice(start, start + PAGE_SIZE);

    const container  = document.getElementById('events_table_container');
    const pagination = document.getElementById('events_pagination');

    if (total === 0) {
        container.innerHTML  = '<p class="no-results">No events match your filters.</p>';
        pagination.innerHTML = '';
        return;
    }

    let html = `<p class="section-count">Showing ${start+1}–${Math.min(start+PAGE_SIZE,total)} of ${total} events</p>`;
    html += `<table><tr>
        <th>#</th><th>Event Name</th><th>Venue</th><th>Faculty Coordinator</th>
        <th>School</th><th>Phone Number</th>
        <th>Event Type</th><th>Day Type</th><th>Date(s)</th><th>Time</th><th>Action</th>
    </tr>`;

    slice.forEach((ev, i) => {
        const typeLabel   = ev.multiday ? '<span class="badge-multi">Multi-day</span>' : 'Single Day';
        const dateDisplay = ev.multiday
            ? `${formatDate(ev.date)} – ${formatDate(ev.end_date || ev.date)}`
            : formatDate(ev.date);
        const timeDisplay = ev.multiday
            ? (ev.days ? ev.days.map(d => `${formatDate(d.date)}: ${d.time}`).join('<br>') : '–')
            : (ev.time || '–');
        const safeName = ev.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
        const evTypeBadge = ev.event_type
            ? `<span style="background:#e8e0ff;color:rgb(27,0,93);font-size:11px;padding:2px 7px;border-radius:10px;white-space:nowrap;">${ev.event_type}</span>`
            : '–';

        html += `<tr>
            <td>${start+i+1}</td>
            <td>${ev.name}</td>
            <td>${ev.venue}</td>
            <td>${ev.organising_team || '–'}</td>
            <td>${ev.school || '–'}</td>
            <td>${ev.phone_number || '–'}</td>
            <td>${evTypeBadge}</td>
            <td>${typeLabel}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;">${timeDisplay}</td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete event \\'${safeName}\\'. This cannot be undone.');">
                    <input type="hidden" name="event_id" value="${ev.id}">
                    <button type="submit" name="delete_event" class="btn-delete">🗑 Delete</button>
                </form>
            </td>
        </tr>`;
    });

    html += '</table>';
    container.innerHTML = html;

    let pHtml = '';
    for (let p = 1; p <= pages; p++) {
        pHtml += `<button class="${p === eventsCurrentPage ? 'active-page' : ''}" onclick="goEventsPage(${p})">${p}</button>`;
    }
    pagination.innerHTML = pHtml;
}

function goEventsPage(p) { eventsCurrentPage = p; renderEventsPage(); }
function clearEvFilters() {
    document.getElementById('ev_search').value    = '';
    document.getElementById('ev_date_from').value = '';
    document.getElementById('ev_date_to').value   = '';
    document.getElementById('ev_type_filter').value = '';
    document.getElementById('ev_sort').value      = 'newest';
    renderEventsTable();
}

function getFilteredHistory() {
    const search   = document.getElementById('admin_search').value.toLowerCase();
    const dateFrom = document.getElementById('admin_date_from').value;
    const dateTo   = document.getElementById('admin_date_to').value;
    const sort     = document.getElementById('admin_sort').value;

    let data = allHistoryData.filter(r => {
        const matchText = !search ||
            r.events_text.toLowerCase().includes(search) ||
            r.date_range.toLowerCase().includes(search) ||
            (r.organising_teams||'').toLowerCase().includes(search);
        const matchFrom = !dateFrom || r.date_to   >= dateFrom;
        const matchTo   = !dateTo   || r.date_from <= dateTo;
        return matchText && matchFrom && matchTo;
    });

    data.sort((a, b) => {
        if (sort === 'newest')          return b.segregated_on.localeCompare(a.segregated_on);
        if (sort === 'oldest')          return a.segregated_on.localeCompare(b.segregated_on);
        if (sort === 'event_date_asc')  return a.date_from.localeCompare(b.date_from);
        if (sort === 'event_date_desc') return b.date_from.localeCompare(a.date_from);
        return 0;
    });
    return data;
}

function renderAdminTable() { adminCurrentPage = 1; renderAdminPage(); }

function renderAdminPage() {
    const data    = getFilteredHistory();
    const total   = data.length;
    const pages   = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start   = (adminCurrentPage - 1) * PAGE_SIZE;
    const slice   = data.slice(start, start + PAGE_SIZE);

    const container  = document.getElementById('admin_table_container');
    const pagination = document.getElementById('admin_pagination');

    if (total === 0) {
        container.innerHTML  = '<p class="no-results">No records match your filters.</p>';
        pagination.innerHTML = '';
        return;
    }

    let html = `<p class="section-count">Showing ${start+1}–${Math.min(start+PAGE_SIZE,total)} of ${total} records</p>`;
    html += `<table class="history-table"><tr>
        <th>#</th><th>Event Date(s)</th><th>Events Segregated</th>
        <th>Faculty Coordinator (School | Phone)</th><th>Count</th><th>Segregated On</th><th>Download</th><th>Action</th>
    </tr>`;

    slice.forEach((r, i) => {
        const drFrom = r.date_from ? formatDate(r.date_from) : '';
        const drTo   = r.date_to   ? formatDate(r.date_to)   : '';
        const dateDisplay = (drFrom && drTo && drFrom !== drTo) ? drFrom + ' – ' + drTo : drFrom;

        const evLines = r.events_text.split('\n').map(line =>
            `<div style="margin-bottom:3px;">• ${line}</div>`
        ).join('');

        const zipLinks = (r.zips||[]).filter(z => z.endsWith('.zip')).map(z =>
            `<a href="downloads/${z}" target="_blank" style="display:block;margin-bottom:3px;">📦 ${z}</a>`
        ).join('') || '–';

        html += `<tr>
            <td>${start+i+1}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;line-height:1.6;">${evLines}</td>
            <td style="font-size:12px;line-height:1.7;">${r.organising_teams||'–'}</td>
            <td style="text-align:center;">${r.event_count}</td>
            <td style="white-space:nowrap;">${formatDateTime(r.segregated_on)}</td>
            <td style="font-size:12px;">${zipLinks}</td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete this history record? This cannot be undone.');">
                    <input type="hidden" name="history_id" value="${r.id}">
                    <button type="submit" name="delete_history" class="btn-delete">🗑 Delete</button>
                </form>
            </td>
        </tr>`;
    });

    html += '</table>';
    container.innerHTML = html;

    let pHtml = '';
    for (let p = 1; p <= pages; p++) {
        pHtml += `<button class="${p === adminCurrentPage ? 'active-page' : ''}" onclick="goAdminPage(${p})">${p}</button>`;
    }
    pagination.innerHTML = pHtml;
}

function goAdminPage(p) { adminCurrentPage = p; renderAdminPage(); }
function clearAdminFilters() {
    document.getElementById('admin_search').value    = '';
    document.getElementById('admin_date_from').value = '';
    document.getElementById('admin_date_to').value   = '';
    document.getElementById('admin_sort').value      = 'newest';
    renderAdminTable();
}

/* ==================== PAGE LOAD ==================== */
window.addEventListener("load", function () {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab === 'segregation' || urlParams.has('segregation')) {
        showPage('segregation');
    } else if (tab === 'admin') {
        showPage('admin');
    } else {
        modal.style.display = 'block';
    }
    renderEventsTable();
    renderAdminTable();
});
</script>


<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
/* ==================== ANALYTICS ==================== */
const monthLabels           = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const dowLabels             = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const allEventsForAnalytics = <?php echo json_encode($events); ?>;
const segregMonthlyData     = <?php echo json_encode(array_values($segregMonthly)); ?>;
const heatmapByDateAll      = <?php echo json_encode($heatmapByDate); ?>;
const venueLabels           = <?php echo json_encode(array_keys(array_slice($venueCounts,0,10,true))); ?>;
const venueCounts_          = <?php echo json_encode(array_values(array_slice($venueCounts,0,10,true))); ?>;
const teamLabels            = <?php echo json_encode(array_keys(array_slice($teamCounts,0,10,true))); ?>;
const teamCounts_           = <?php echo json_encode(array_values(array_slice($teamCounts,0,10,true))); ?>;
const schoolLabelsJS        = <?php echo json_encode(array_keys($schoolStudentCounts)); ?>;
const schoolCountsJS        = <?php echo json_encode(array_values($schoolStudentCounts)); ?>;
const eventParticipationJS  = <?php echo json_encode($eventParticipationArr); ?>;
const totalSegRunsAll       = <?= $totalSegregationRuns ?>;
const totalPendingAll       = <?= $totalPending ?>;
const totalStudentsAll      = <?= $totalStudentsAllRuns ?>;
const avgStudentsPerRunAll  = <?= $avgStudentsPerRun ?>;
const avgEventsPerRunAll    = <?= $avgEventsPerRun ?>;
const allSegHistoryJS       = <?php
    $js = [];
    foreach($historyRaw as $h) {
        $js[] = ['segregated_on'=>$h['segregated_on'],'date_from'=>$h['date_from'],'date_to'=>$h['date_to']];
    }
    echo json_encode($js);
?>;

const baseColor    = 'rgb(27,0,93)';
const accentColor  = 'rgb(90,0,200)';
const lightColor   = 'rgba(27,0,93,0.15)';
const goldColor    = 'rgb(255,193,7)';
const typeColors   = ['#1b005d','#3d00c8','#ffc107','#e83e8c','#20c997','#fd7e14','#6610f2','#17a2b8'];
const dowBarColors = ['#6610f2','#6610f2','#e83e8c','#6610f2','#e83e8c','#e83e8c',goldColor];

let chartEvM=null, chartSegM=null, chartComp=null, chartDOW=null, chartDay=null, chartType=null;
let chartsInitialized = false;
let currentTimePill = 'alltime';

/* ===== TIME PILL LOGIC ===== */
function setTimePill(pill) {
    currentTimePill = pill;
    ['alltime','year','month','mrange'].forEach(p => {
        document.getElementById('pill_'+p).classList.toggle('active', p===pill);
        const el = document.getElementById('time_inputs_'+p);
        if (el) el.style.display = (p!=='alltime' && p===pill) ? 'flex' : 'none';
    });
    const labels = {alltime:'All Time', year:'Year', month:'Month', mrange:'Month Range'};
    document.getElementById('showing_label').textContent = 'Showing: ' + labels[pill];
    applyAnalyticsFilter();
}

function getTimeBounds() {
    const pill = currentTimePill;
    if (pill === 'alltime') return [null, null];
    if (pill === 'year') {
        const y = document.getElementById('filter_year').value;
        return [`${y}-01-01`, `${y}-12-31`];
    }
    if (pill === 'month') {
        const y = document.getElementById('filter_month_year').value;
        const m = document.getElementById('filter_month_month').value;
        const lastDay = new Date(parseInt(y), parseInt(m), 0).getDate();
        return [`${y}-${m}-01`, `${y}-${m}-${String(lastDay).padStart(2,'0')}`];
    }
    if (pill === 'mrange') {
        const fy = document.getElementById('filter_mrange_from_year').value;
        const fm = document.getElementById('filter_mrange_from_month').value;
        const ty = document.getElementById('filter_mrange_to_year').value;
        const tm = document.getElementById('filter_mrange_to_month').value;
        const lastDay = new Date(parseInt(ty), parseInt(tm), 0).getDate();
        return [`${fy}-${fm}-01`, `${ty}-${tm}-${String(lastDay).padStart(2,'0')}`];
    }
    return [null, null];
}

/* ===== FILTER HELPERS ===== */
function getAnalyticsFilteredEvents() {
    const typeF = (document.getElementById('analytics_type_filter')?.value) || 'all';
    const [from, to] = getTimeBounds();
    return allEventsForAnalytics.filter(ev => {
        if (typeF !== 'all' && ev.event_type !== typeF) return false;
        if (from && (ev.date||'') < from) return false;
        if (to   && (ev.date||'') > to)   return false;
        return true;
    });
}

function computeMonthly(evArr) {
    const m = Array(12).fill(0);
    evArr.forEach(ev => {
        const mo = parseInt((ev.date||'').split('-')[1]||'0') - 1;
        if (mo >= 0 && mo < 12) m[mo]++;
    });
    return m;
}
function computeDaySplit(evArr) {
    let s=0, m=0;
    evArr.forEach(ev => { if (ev.multiday) m++; else s++; });
    return [s, m];
}
function computeTypeCounts(evArr) {
    const c = {};
    evArr.forEach(ev => { if (ev.event_type) c[ev.event_type] = (c[ev.event_type]||0)+1; });
    return c;
}
function computeDOW(evArr) {
    const d = Array(7).fill(0);
    evArr.forEach(ev => {
        if (!ev.date) return;
        const dt = new Date(ev.date + 'T12:00:00');
        if (!isNaN(dt)) d[dt.getDay()]++;
    });
    return d;
}

/* ===== DYNAMIC SMART INSIGHTS ===== */
function buildInsights(evArr) {
    const lines    = [];
    const monthly  = computeMonthly(evArr);
    const [s, m]   = computeDaySplit(evArr);
    const dow      = computeDOW(evArr);
    const typeCnts = computeTypeCounts(evArr);
    const total    = evArr.length;
    const [from, to] = getTimeBounds();

    const g = (v) => `<span style="color:#ffc107;font-weight:800;">${v}</span>`;

    if (total === 0) {
        lines.push('→ No events match the selected filter.');
    } else {
        const peakMoIdx = monthly.indexOf(Math.max(...monthly));
        if (Math.max(...monthly) > 0)
            lines.push(`→ 🗓️ ${g(monthLabels[peakMoIdx])} was the most active month (${monthly[peakMoIdx]} events).`);

        let runsInRange = totalSegRunsAll;
        if (from || to) {
            runsInRange = allSegHistoryJS.filter(h => {
                const d = (h.date_from || (h.segregated_on||'').split(' ')[0]);
                return (!from || d >= from) && (!to || d <= to);
            }).length;
        }
        if (runsInRange > 0)
            lines.push(`→ ⚡ ${g(runsInRange)} segregation(s) done with avg ${g(avgEventsPerRunAll)} events each.`);

        if (totalStudentsAll > 0)
            lines.push(`→ 🎓 ${g(totalStudentsAll.toLocaleString())} student records processed (avg ${g(avgStudentsPerRunAll.toLocaleString())}/segregation).`);

        if (m > 0) {
            const pct = Math.round(m / Math.max(total,1) * 100);
            lines.push(`→ 🗓️ ${g(pct+'%')} of events are multi-day (${m} of ${total}).`);
        }

        // Most attended event
        if (eventParticipationJS && eventParticipationJS.length > 0) {
            const top = eventParticipationJS[0];
            lines.push(`→ 🏆 Most attended event: ${g(top.name)} (${g(top.count.toLocaleString())} students across ${top.schools} school${top.schools!==1?'s':''}).`);
            if (eventParticipationJS.length > 1) {
                const least = eventParticipationJS[eventParticipationJS.length - 1];
                lines.push(`→ 📉 Least attended event: ${g(least.name)} (${least.count.toLocaleString()} students).`);
            }
        }

        // Most participating school
        if (schoolLabelsJS.length > 0)
            lines.push(`→ 🏫 Most participating school: ${g(schoolLabelsJS[0])} (${schoolCountsJS[0].toLocaleString()} students).`);

        // Most common event type
        const sortedTypes = Object.entries(typeCnts).sort((a,b)=>b[1]-a[1]);
        if (sortedTypes.length > 0 && sortedTypes[0][1] > 0)
            lines.push(`→ 🏷️ Most common event type: ${g(sortedTypes[0][0])} (${sortedTypes[0][1]} events).`);

        if (venueLabels.length > 0)
            lines.push(`→ 🏛️ Top venue: ${g(venueLabels[0])} (${venueCounts_[0]} event${venueCounts_[0]>1?'s':''}).`);

        if (teamLabels.length > 0)
            lines.push(`→ 👥 Most active team: ${g(teamLabels[0].toUpperCase())} (${teamCounts_[0]} event${teamCounts_[0]>1?'s':''}).`);

        const peakDowIdx = dow.indexOf(Math.max(...dow));
        if (Math.max(...dow) > 0)
            lines.push(`→ 📆 Busiest day: ${g(dowLabels[peakDowIdx])} (${dow[peakDowIdx]} events).`);
    }

    const container = document.getElementById('insight_lines_dynamic');
    if (container) container.innerHTML = lines.map(l => `<div class="insight-line">${l}</div>`).join('');
    const box = document.getElementById('insight_box_dynamic');
    if (box) box.style.display = lines.length ? '' : 'none';
}

/* ===== APPLY FILTER — update charts in-place, no destroy ===== */
function applyAnalyticsFilter() {
    const evArr      = getAnalyticsFilteredEvents();
    const monthly    = computeMonthly(evArr);
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const dow        = computeDOW(evArr);
    const mx_m       = Math.max(...monthly, 1);
    const mx_d       = Math.max(...dow, 1);

    // Update KPI values
    const upd = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    upd('kpi_total_events', evArr.length);
    upd('kpi_single', s);
    upd('kpi_multi',  m);

    // Always update insights (no chart dependency)
    buildInsights(evArr);

    if (!chartsInitialized) return;

    // Update charts IN PLACE (no destroy)
    if (chartEvM) {
        chartEvM.data.datasets[0].data = monthly;
        chartEvM.data.datasets[0].backgroundColor = monthly.map(v => v === mx_m && v > 0 ? goldColor : baseColor);
        chartEvM.update('none');
    }
    if (chartComp) {
        chartComp.data.datasets[0].data = monthly;
        chartComp.update('none');
    }
    if (chartDay) {
        chartDay.data.datasets[0].data = [s, m];
        chartDay.update('none');
    }
    if (chartDOW) {
        chartDOW.data.datasets[0].data = dow;
        chartDOW.data.datasets[0].backgroundColor = dow.map((v,i) => v === mx_d && v > 0 ? goldColor : dowBarColors[i]);
        chartDOW.update('none');
    }
    if (chartType) {
        const lbs = Object.keys(typeCounts);
        const vs  = Object.values(typeCounts);
        chartType.data.labels = lbs;
        chartType.data.datasets[0].data = vs;
        chartType.data.datasets[0].backgroundColor = typeColors.slice(0, lbs.length);
        chartType.update('none');
    }
    renderEventTypeTable(typeCounts);
}

/* ===== GitHub-style Calendar Heatmap ===== */
let heatmapYear = new Date().getFullYear();
function heatmapPrevYear() { heatmapYear--; document.getElementById('heatmapYearLabel').textContent = heatmapYear; renderGithubHeatmap(heatmapByDateAll, heatmapYear); }
function heatmapNextYear() { heatmapYear++; document.getElementById('heatmapYearLabel').textContent = heatmapYear; renderGithubHeatmap(heatmapByDateAll, heatmapYear); }

function renderGithubHeatmap(dateMap, year) {
    const container = document.getElementById('heatmapContainer');
    if (!container) return;
    const startDate   = new Date(year, 0, 1);
    const endDate     = new Date(year, 11, 31);
    const firstSunday = new Date(startDate);
    firstSunday.setDate(firstSunday.getDate() - startDate.getDay());

    const weeks = [];
    let cur = new Date(firstSunday);
    while (cur <= endDate) {
        const week = [];
        for (let d=0; d<7; d++) { week.push(new Date(cur)); cur.setDate(cur.getDate()+1); }
        weeks.push(week);
    }

    const maxVal = Math.max(1, ...Object.values(dateMap).map(Number));
    const shortMon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    let monthRow = '<div style="display:flex;margin-bottom:2px;margin-left:32px;">';
    let lastMonth = -1;
    weeks.forEach(week => {
        const fiy = week.find(d => d.getFullYear() === year);
        let label = '';
        if (fiy) { const mo = fiy.getMonth(); if (mo !== lastMonth) { label = shortMon[mo]; lastMonth = mo; } }
        monthRow += `<div style="width:14px;flex-shrink:0;font-size:10px;color:#888;">${label}</div>`;
    });
    monthRow += '</div>';

    const dowShort = ['','Mon','','Wed','','Fri',''];
    let grid = '<div style="display:flex;"><div style="display:flex;flex-direction:column;margin-right:4px;">';
    for (let di=0; di<7; di++)
        grid += `<div style="height:14px;line-height:14px;font-size:10px;color:#888;white-space:nowrap;margin-bottom:2px;">${dowShort[di]}</div>`;
    grid += '</div>';

    weeks.forEach(week => {
        grid += '<div style="display:flex;flex-direction:column;gap:2px;margin-right:2px;">';
        week.forEach(day => {
            const iso  = day.toISOString().split('T')[0];
            const inY  = day.getFullYear() === year;
            const val  = inY ? (Number(dateMap[iso]) || 0) : 0;
            const pct  = val / maxVal;
            let bg = !inY ? 'transparent' : val === 0 ? '#edf2ff' : pct < 0.25 ? '#9b9fd4' : pct < 0.5 ? '#6366c1' : pct < 0.75 ? '#4338ca' : '#1b005d';
            const title = (inY && val > 0) ? `${val} event(s) on ${iso}` : iso;
            grid += `<div title="${title}" style="width:12px;height:12px;border-radius:2px;background:${bg};"></div>`;
        });
        grid += '</div>';
    });
    grid += '</div>';
    container.innerHTML = monthRow + grid;
}

/* ===== Event type breakdown table ===== */
function renderEventTypeTable(typeCounts) {
    const c = document.getElementById('event_type_table_container');
    if (!c) return;
    const total = Object.values(typeCounts).reduce((a,b) => a+b, 0);
    if (total === 0) { c.innerHTML = '<p style="color:#888;font-style:italic;">No events in this filter.</p>'; return; }
    let html = '<table><tr><th>Event Type</th><th>Count</th><th>Share</th></tr>';
    Object.entries(typeCounts).sort((a,b) => b[1]-a[1]).forEach(([type, count]) => {
        const pct = total > 0 ? Math.round(count/total*100) : 0;
        html += `<tr><td><span style="background:#e8e0ff;color:rgb(27,0,93);font-size:11px;padding:2px 7px;border-radius:10px;">${type}</span></td>
        <td><strong>${count}</strong></td>
        <td><div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;background:#f0f0f0;border-radius:4px;height:8px;">
                <div style="width:${pct}%;background:rgb(27,0,93);height:8px;border-radius:4px;"></div>
            </div>
            <span style="font-size:12px;color:#555;">${pct}%</span>
        </div></td></tr>`;
    });
    html += '</table>';
    c.innerHTML = html;
}

/* ===== initCharts — called ONCE when analytics tab opens ===== */
function initCharts() {
    if (chartsInitialized) return; // Only ever called once
    chartsInitialized = true;

    const evArr      = getAnalyticsFilteredEvents();
    const monthly    = computeMonthly(evArr);
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const typeLabels = Object.keys(typeCounts);
    const typeVals   = Object.values(typeCounts);
    const dow        = computeDOW(evArr);
    const mx_m       = Math.max(...monthly, 1);
    const mx_s       = Math.max(...segregMonthlyData, 1);
    const mx_d       = Math.max(...dow, 1);

    // 1. Monthly events bar
    chartEvM = new Chart(document.getElementById('chartEventsMonthly'), {
        type: 'bar',
        data: { labels: monthLabels, datasets: [{ label: 'Events', data: monthly,
            backgroundColor: monthly.map(v => v===mx_m&&v>0 ? goldColor : baseColor),
            borderRadius: 6, borderSkipped: false }] },
        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });

    // 2. Segregations done bar (global — not filtered by time/type)
    chartSegM = new Chart(document.getElementById('chartSegregMonthly'), {
        type: 'bar',
        data: { labels: monthLabels, datasets: [{ label: 'Segregations Done', data: segregMonthlyData,
            backgroundColor: segregMonthlyData.map(v => v===mx_s&&v>0 ? goldColor : accentColor),
            borderRadius: 6, borderSkipped: false }] },
        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });

    // 3. Registered vs Segregated trend line
    chartComp = new Chart(document.getElementById('chartComparison'), {
        type: 'line',
        data: { labels: monthLabels, datasets: [
            { label:'Events Registered', data:monthly, borderColor:baseColor, backgroundColor:lightColor, fill:true, tension:0.4, pointBackgroundColor:baseColor, pointRadius:4 },
            { label:'Segregations Done', data:segregMonthlyData, borderColor:goldColor, backgroundColor:'rgba(255,193,7,0.1)', fill:true, tension:0.4, pointBackgroundColor:goldColor, pointRadius:4 }
        ]},
        options: { plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:12}}}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });

    // 4. Single vs Multi donut
    chartDay = new Chart(document.getElementById('chartTypeSplit'), {
        type: 'doughnut',
        data: { labels:['Single-Day','Multi-Day'], datasets:[{ data:[s,m], backgroundColor:[baseColor,goldColor], borderWidth:0, hoverOffset:8 }] },
        options: { cutout:'65%', plugins:{ legend:{position:'bottom',labels:{boxWidth:14,font:{size:12}}},
            tooltip:{callbacks:{label:ctx=>{const t=s+m;return ` ${ctx.label}: ${ctx.parsed} (${t>0?Math.round(ctx.parsed/t*100):0}%)`;}}}} ,
            responsive:true, animation:false }
    });

    // 5. Busiest Days of Week horizontal bar
    chartDOW = new Chart(document.getElementById('chartDOW'), {
        type: 'bar',
        data: { labels: dowLabels, datasets: [{ label:'Events', data:dow,
            backgroundColor: dow.map((v,i) => v===mx_d&&v>0 ? goldColor : dowBarColors[i]),
            borderRadius: 4 }] },
        options: { indexAxis:'y', plugins:{legend:{display:false}},
            scales:{x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},y:{grid:{display:false},ticks:{font:{size:12}}}},
            responsive:true, animation:false }
    });

    // 6. Event type donut
    chartType = new Chart(document.getElementById('chartEventTypeSplit'), {
        type: 'doughnut',
        data: { labels:typeLabels, datasets:[{ data:typeVals, backgroundColor:typeColors.slice(0,typeLabels.length), borderWidth:0, hoverOffset:8 }] },
        options: { cutout:'55%', plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},
            tooltip:{callbacks:{label:ctx=>{const t=typeVals.reduce((a,b)=>a+b,0);return ` ${ctx.label}: ${ctx.parsed} (${t>0?Math.round(ctx.parsed/t*100):0}%)`;}}}} ,
            responsive:true, animation:false }
    });

    renderGithubHeatmap(heatmapByDateAll, heatmapYear);
    renderEventTypeTable(typeCounts);
    buildInsights(evArr);
}

/* ===== CONFIRM ADD EVENT ===== */
function confirmAddEvent() {
    return confirm("Are you sure you want to add this event?\n\nClick OK to confirm or Cancel to go back.");
}

/* ===== DOWNLOAD ANALYTICS REPORT — TXT ===== */
function downloadAnalyticsTXT() {
    const evArr      = getAnalyticsFilteredEvents();
    const typeF      = (document.getElementById('analytics_type_filter')?.value) || 'all';
    const [from, to] = getTimeBounds();
    const pill       = currentTimePill;
    const pillLabels = {alltime:'All Time', year:'Year', month:'Month', mrange:'Month Range'};
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const monthly    = computeMonthly(evArr);
    const dow        = computeDOW(evArr);
    const now        = new Date().toLocaleString('en-IN');
    const typeLabel  = typeF === 'all' ? 'All Event Types' : typeF;
    let timeLabel    = pillLabels[pill] || 'All Time';
    if (from && to)  timeLabel += ` (${from} to ${to})`;
    const totalType  = Object.values(typeCounts).reduce((a,b)=>a+b,0);

    const sep  = '=======================================================';
    const dash = '-------------------------------------------------------';
    const lines = [];

    lines.push(sep);
    lines.push('  VIT SMART ATTENDANCE SEGREGATOR - Analytics Summary');
    lines.push(`  Generated   : ${now}`);
    lines.push(`  Time Filter : ${timeLabel}`);
    lines.push(`  Event Type  : ${typeLabel}`);
    lines.push(sep);
    lines.push('');

    lines.push('--- KEY METRICS ---');
    lines.push(`  Total Events (filtered)   : ${evArr.length}`);
    lines.push(`  Single-Day Events         : ${s}`);
    lines.push(`  Multi-Day Events          : ${m}`);
    lines.push(`  Total Segregations Done   : ${totalSegRunsAll}`);
    lines.push(`  Total Students Processed  : ${totalStudentsAll.toLocaleString()}`);
    const avgSt = totalSegRunsAll > 0 ? Math.round(totalStudentsAll / totalSegRunsAll).toLocaleString() : '0';
    lines.push(`  Avg Students/Segregation  : ${avgSt}`);
    lines.push(`  Pending Segregation       : ${totalPendingAll}`);
    lines.push('');

    lines.push('--- MONTHLY EVENT BREAKDOWN ---');
    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    monthly.forEach((cnt, i) => lines.push(`  ${monthNames[i].padEnd(5)}: ${cnt}`));
    lines.push('');

    lines.push('--- BUSIEST DAY OF WEEK ---');
    const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    dow.forEach((cnt, i) => lines.push(`  ${dayNames[i].padEnd(12)}: ${cnt}`));
    lines.push('');

    lines.push('--- EVENT TYPE BREAKDOWN ---');
    if (totalType === 0) {
        lines.push('  No data yet.');
    } else {
        Object.entries(typeCounts).sort((a,b)=>b[1]-a[1]).forEach(([t,c]) => {
            if (c > 0) lines.push(`  ${t.padEnd(38)}: ${c} (${Math.round(c/totalType*100)}%)`);
        });
    }
    lines.push('');

    lines.push('--- EVENT PARTICIPATION ---');
    if (!eventParticipationJS || eventParticipationJS.length === 0) {
        lines.push('  No data yet.');
    } else {
        eventParticipationJS.forEach((ep, i) =>
            lines.push(`  ${String(i+1)+'. '+ep.name}`.padEnd(42) + `: ${ep.count.toLocaleString()} students (${ep.schools} school file(s))`)
        );
    }
    lines.push('');

    lines.push('--- SCHOOL-WISE ATTENDANCE ---');
    if (!schoolLabelsJS || schoolLabelsJS.length === 0) {
        lines.push('  No data yet.');
    } else {
        schoolLabelsJS.forEach((sc, i) =>
            lines.push(`  ${sc.padEnd(15)}: ${schoolCountsJS[i].toLocaleString()} students`)
        );
    }
    lines.push('');

    lines.push('--- VENUE UTILISATION (Top 10) ---');
    if (!venueLabels || venueLabels.length === 0) {
        lines.push('  No data yet.');
    } else {
        venueLabels.forEach((v, i) =>
            lines.push(`  ${v.padEnd(40)}: ${venueCounts_[i]} event${venueCounts_[i]!==1?'s':''}`)
        );
    }
    lines.push('');

    lines.push('--- FACULTY COORDINATOR LEADERBOARD (Top 10) ---');
    if (!teamLabels || teamLabels.length === 0) {
        lines.push('  No data yet.');
    } else {
        teamLabels.forEach((t, i) =>
            lines.push(`  ${t.padEnd(40)}: ${teamCounts_[i]} event${teamCounts_[i]!==1?'s':''}`)
        );
    }
    lines.push('');

    lines.push(sep);
    lines.push('  VIT-IST | Office of Innovation, Startup & Technology Transfer');
    lines.push(sep);

    const text  = lines.join('\n');
    const fname = `VIT_Analytics_${pill}_${typeF==='all'?'All':typeF.replace(/[^a-z0-9]/gi,'_')}_${Date.now()}.txt`;
    const blob  = new Blob([text], {type:'text/plain'});
    const url   = URL.createObjectURL(blob);
    const a     = document.createElement('a');
    a.href = url; a.download = fname; a.click();
    URL.revokeObjectURL(url);
}
</script>

<!-- ===== EVENT ADDED SUCCESS POPUP ===== -->
<?php if (isset($_GET['event_added']) && $_GET['event_added'] == '1'): ?>
<div id="successPopup" style="
    position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,0.45);z-index:9999;
    display:flex;align-items:center;justify-content:center;">
    <div style="
        background:white;border-radius:16px;padding:40px 48px;
        text-align:center;box-shadow:0 8px 40px rgba(27,0,93,0.25);
        max-width:420px;width:90%;animation:popIn 0.3s ease;">
        <div style="font-size:52px;margin-bottom:12px;">✅</div>
        <h2 style="color:rgb(27,0,93);margin-bottom:10px;font-size:20px;">Event Added Successfully!</h2>
        <p style="color:#555;font-size:14px;margin-bottom:24px;">The event has been registered in the system.</p>
        <button onclick="document.getElementById('successPopup').style.display='none'"
            style="padding:10px 32px;background:rgb(27,0,93);color:white;border:none;
            border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">OK</button>
    </div>
</div>
<style>
@keyframes popIn {
    from { transform:scale(0.8); opacity:0; }
    to   { transform:scale(1);   opacity:1; }
}
</style>
<?php endif; ?>

</body>
</html>