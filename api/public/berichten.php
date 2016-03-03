<?php

ini_set("error_reporting", 1);
ini_set("display_errors", E_ALL);

$filePath = "../files/messages.json";
$coachUri = "http://coach.fixxx.nl";

function randomHash() {
	$alphabet = "0123456789abcdefg";
	$hash = "";
	while (strlen($hash) < 40) {
		$hash .= $alphabet[mt_rand(0, strlen($alphabet))];
	}
	return $hash;
}

function loadMessages() {
	global $filePath;
	$messagesJson = file_get_contents($filePath);
	$messages = json_decode($messagesJson);
	if (!$messages) {
		$messages = []; // FIXME backup here?
	}
	$messages = (array) $messages;
	return $messages;
}

function backupMessagesFile() {
	global $filePath;
	$backupFilePath = $filePath . ".backup." . date("Y-m-d");
	if (!file_exists($backupFilePath)) {
		copy($filePath, $backupFilePath);
	}
}

function indexMessages($messages) {
	return array_combine(
		array_map(function ($message) {
			return $message->id;
		}, $messages),
		array_values($messages)
	);
}

function saveMessages($messages) {
	global $filePath;
	backupMessagesFile();
	if (!is_array($messages)) {
		throw new Exception("Cannot store messages; Format invalid (needs to be an array).");
	}
	$messages = indexMessages($messages); //FIXME remove this later.
	file_put_contents($filePath, json_encode($messages));
}

$messageFields = [
	"id",
	"title",
	"body",
	"advice",
	"title_en",
	"body_en",
	"advice_en",
	"title_fr",
	"body_fr",
	"advice_fr",
	"startdate",
	"enddate",
	"category",
	"link",
	"image_url"
];

$messages = loadMessages();

switch ($_SERVER["REQUEST_METHOD"]) {
	case "POST":
		$message = (object) [];
		foreach ($messageFields as $messageField) {
			$message->{$messageField} = !empty($_POST[$messageField]) ?
				$_POST[$messageField] : "";
		}
		if (empty($message->id)) {
			$message->id = randomHash();
		}
		$messages[$message->id] = $message;
		saveMessages($messages);
		header("Content-type: application/json");
		echo json_encode($message);
		exit;

	case "GET":
		$messages = loadMessages();

        // Make sure all the fields are there.
		$messages = array_map(function ($message) use ($messageFields) {
			foreach ($messageFields as $messageField) {
				if (!isset($message->{$messageField})) {
					$message->{$messageField} = "";
				}
			}
			return $message;
		}, $messages);

        // Sort messages by date.
		uasort($messages, function ($messageA, $messageB) {
			return $messageA->startdate < $messageB->startdate;
		});

        // If link is available, and it is a link to google maps
        // Get location geo information.
        $messages = array_map(function ($message) {
            $message->link_info = "";
            $message->location = (object)[];
            if (preg_match("/goo\.gl/", $message->link)) {
                $ch = curl_init($message->link);
                curl_setopt($ch, CURLOPT_NOBODY, 1);
                $rs = curl_exec($ch);
                $message->link_info = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $matches = [];
                preg_match("/@([0-9.]*),([0-9.]*),/", $message->link_info, $matches);
                $message->location = [
                    "lat" => $matches[1],
                    "lng" => $matches[2]
                ];
            }
            if (preg_match("/google\..*\/maps\//", $message->link)) {
                $matches = [];
                preg_match("/@([0-9.]*),([0-9.]*),/", $message->link, $matches);
                $message->location = [
                    "lat" => $matches[1],
                    "lng" => $matches[2]
                ];
            }
            return $message;
        }, $messages);

		$uriParts = array_values(array_filter(explode("/", explode("?", $_SERVER["REQUEST_URI"])[0])));

        // If message id is present (40 char length)
		// Get One and Exit.
		if (!empty($uriParts[1]) && strlen($uriParts[1]) === 40) {
			$id = $uriParts[1];
			$message = $messages[$id];
			header("Content-type: application/json");
			echo json_encode([
				"message" => $message
			]);
			exit;
		}

		$date = date("Y-m-d");

		// Filter by date.
		if (!empty($uriParts[1]) && strlen($uriParts[1]) === 4) {
			$date = "{$uriParts[1]}-{$uriParts[2]}-{$uriParts[3]}";
			$messages = array_values(array_filter($messages, function ($message) use ($date) {
				return $message->startdate <= $date &&
				       $message->enddate >= $date;
			}));
		}

		header("Content-type: application/json");
		echo json_encode([
			"_date" => $date,
			"_nextDate" => date("Y-m-d", strtotime("+1 day", strtotime($date))), 
			"_prevDate" => date("Y-m-d", strtotime("-1 day", strtotime($date))), 
			"messages" => $messages
		]);
		exit;

	case "DELETE":
		$messages = loadMessages();
		$uriParts = array_values(array_filter(explode("/", explode("?", $_SERVER["REQUEST_URI"])[0])));
		$id = $uriParts[1];
		$ids = $id ? [$id] : $_GET["ids"];
		$messages = array_diff_key($messages, array_flip($ids));
		saveMessages($messages);
		exit;
}

