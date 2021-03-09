<?php
	// Установка временной зоны Москва
	date_default_timezone_set('Europe/Moscow');

    // Константы
    const TOKEN = '';
	const BASE_URL = 'https://api.telegram.org/bot'.TOKEN.'/';

	// Выполнение скрипта
	$content = file_get_contents('php://input');
	$update = json_decode($content, JSON_OBJECT_AS_ARRAY);
	if ($update['message']['chat']['id'] == -1)
	{
		file_put_contents("last message.txt", $content);
	}
	main($update);

	// Функции
	function sendRequest($method, $params = [])
	{
		if (!empty($params))
		{
			$url = BASE_URL.$method.'?'.http_build_query($params);
		}
		else
		{
			$url = BASE_URL.$method;
		}
		return json_decode(
			file_get_contents($url),
			JSON_OBJECT_AS_ARRAY
		);
	}

	function sendRequestToDB($request)
	{
	    $mysqli = new mysqli("", "", "", "");
	    $mysqli->query("SET NAMES 'utf8'");
	    $response = $mysqli->query($request);
	    $mysqli->close();
	    return $response;
	} 

	// Получить номер текущей учебной недели
	function getCurrentSchoolWeekNumber()
	{
	    $first_week = sendRequestToDB("SELECT `value` FROM `settings` WHERE `parameter` = 'first_week'");
	    $first_week = (int)$first_week->fetch_assoc()['value'];
	    $this_week = (int)date("W");
	    return $this_week - $first_week + 1;
	}

	// Получить номер учебной недели в момент времени t(unix-формат)
	function getNumberOfSchoolWeek($t)
	{
	    $first_week = sendRequestToDB("SELECT `value` FROM `settings` WHERE `parameter` = 'first_week'");
	    $first_week = (int)$first_week->fetch_assoc()['value'];
	    $this_week = (int)date("W", $t);
	    return $this_week - $first_week + 1;
	}

	// Получить расписание для пользователя user для определённого дня, заданного в виде unix-формата
	function getTimetable($user, $dayUnix)
	{
		$week = getNumberOfSchoolWeek($dayUnix);
		$dayOfWeek = date("N", $dayUnix);
		// Неделя чётная(2) или нечётная(1)
		$periodicity = ($week % 2 ? 1 : 2);
		$group = $user['study_group'];

		$response = sendRequestToDB("SELECT pair_number, lesson_type, name, time, classroom, week_numbers FROM `timetable` WHERE week_day = '$dayOfWeek' AND (periodicity = 0 OR periodicity = $periodicity) ORDER BY pair_number ASC");
			
		if ($response->num_rows == 0)
		{
			return "Пар нет:)\n\n";
		}

		$text = "";
		$format = "%d-ая пара (%s, %s):\n%s %s\n\n";
		while ($row = $response->fetch_assoc())
		{
			if ($row['week_numbers'] == "")
			{
				$text .= sprintf($format, $row['pair_number'], $row['classroom'], $row['time'], $row['name'], $row['lesson_type']);
			}
			else
			{
				$week_numbers = generateListOfWeekNumbers($row['week_numbers']);
				$serch = array_search($week, $week_numbers);
				if ($serch !== False)
				{
					$text .= sprintf($format, $row['pair_number'], $row['classroom'], $row['time'], $row['name'], $row['lesson_type']);
				}
			}
		}
		return $text;
	}

	function getTimeOfNextDayOfWeek($dayNumber)
	{
		$dayOfWeek = (int)date("N");
		$time = time();
		while ($dayOfWeek != $dayNumber)
		{
			$time += 86400;
			$dayOfWeek = date("N", $time);
		}
		return $time;
	}

	// Получить расписание на заданный день недели
	function getTimetableForDayOfWeek($dayOfWeekNumber, $user, $title=NULL)
	{	
		$dayOfWeekNames = array(
		1 => 'понедельник',
		2 => 'вторник',
		3 => 'среду',
		4 => 'четверг',
		5 => 'пятницу',
		6 => 'субботу',
		7 => 'воскресенье');

		if ($title == NULL)
		{
			$dayName = $dayOfWeekNames[$dayOfWeekNumber];	
		}
		else
		{
			$dayName = $title;
		}
		$dayOfWeekTime = getTimeOfNextDayOfWeek($dayOfWeekNumber);
		$dayOfWeekDate = date("j.m", $dayOfWeekTime);
		$text = "Пары на $dayName($dayOfWeekDate):\n\n";
		$text .= getTimetable($user, $dayOfWeekTime);
		
		return $text;
	}

	function generateListOfWeekNumbers($str)
	{
		$parts = explode("-", $str);
		$week_numbers = array();
		for ($i = $parts[0]; $i <= $parts[1]; $i++)
		{
			$week_numbers[] = $i;
		}
		return $week_numbers;
	}

	function main($update)
	{
		$input_message = $update['message']['text'];
		$input_message = mb_strtolower($input_message);
		$chat_id = $update['message']['chat']['id'];
		$first_name = $update['message']['from']['first_name'];
		$last_name = $update['message']['from']['last_name'];

		$time = time();
		$time_str = date("d.m.Y/H:i");
		sendRequestToDB("INSERT INTO `history` VALUES (NULL, $time, '$time_str', $chat_id, '$first_name', '$last_name', '$input_message')");


		if ($input_message == "/start")
		{
			$text = "Вас приветствует бот ...!";
			sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
		}
		
		if ($input_message == "/keyboard" or $input_message == "включить клавиатуру")
		{
			$buttons = 
			[
				["Сегодня", "Завтра"],
				["Неделя", "На неделю"],
				["Понедельник", "Вторник"],
				["Среда", "Четверг"],
				["Пятница", "Суббота"],
				["Сменить группу", "Сменить подгруппу"],
				["Помощь",],
			];

			$keyboard = array();
			$keyboard['keyboard'] = $buttons;
			$keyboard["resize_keyboard"] = TRUE;
			// $keyboard["one_time_keyboard"] = TRUE;

			$text = "Клавиатура активирована)";
			sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'disable_notification' => true, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard, TRUE)]);
			return;
		}

		$user = sendRequestToDB("SELECT * FROM `users` WHERE id = $chat_id");
		if ($user->num_rows == 0)
		{
			sendRequestToDB("INSERT INTO `users` (`id`, `first_name`, `last_name`, `study_group`, `study_subgroup`, `group_request`, `subgroup_request`) VALUES ('$chat_id', '$first_name', '$last_name', '', '', '1', '0')");
			$text = "Пожалуйста, напишите вашу учебную группу";
			sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
		}
		else
		{
			$user = $user->fetch_assoc();
			if ($user['group_request'])
			{
				$input_group = mb_strtoupper($input_message);
				$req = sendRequestToDB("SELECT * FROM `study_groups` WHERE name = '$input_group'");
				if ($req->num_rows == 0)
				{
					sendRequestToDB("UPDATE `users` SET group_request = 0 WHERE id = $chat_id");
					$text = "Расписание для данной группы отсутсвует";
					sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
					return;
				}
				else
				{
					$req = $req->fetch_assoc();
					$input_group = $req['table_name'];
					sendRequestToDB("UPDATE `users` SET group_request = 0, study_group = '$input_group' WHERE id = $chat_id");
					$user['study_group'] = $input_group;
					$text = "Учебная группа успешно установлена";
					sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
					return;
				}
			}
			elseif ($user['subgroup_request'])
			{
				if ($input_message == 1 or $input_message == 2)
				{
					sendRequestToDB("UPDATE `users` SET subgroup_request = 0, study_subgroup = '$input_message' WHERE id = $chat_id");
					$user['study_subgroup'] = $input_message;
					$text = "Учебная подгруппа успешно установлена";
					sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
					return;
				}
				else
				{
					$text = "Введите учебную подгруппу(цифра 1 или цифра 2)";
					sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
					return;
				}
			}
			if ($user['study_group'] == "")
			{
				sendRequestToDB("UPDATE `users` SET group_request = 1 WHERE id = $chat_id");
				$text = "Пожалуйста, напишите вашу учебную группу";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
				return;
			}
			if ($user['study_subgroup'] == 0)
			{
				sendRequestToDB("UPDATE `users` SET subgroup_request = 1 WHERE id = $chat_id");
				$text = "Введите учебную подгруппу(цифра 1 или цифра 2)";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
				return;
			}

			// Что умеет бот?
			if ($input_message == "неделя")
			{
				$week = getCurrentSchoolWeekNumber();
				$text = "$week-ая неделя";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на сегодня" or $input_message == "сегодня")
			{
				$day_of_week = date("N");
				$text = getTimetableForDayOfWeek($day_of_week, $user, "сегодня");
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на завтра" or $input_message == "завтра")
			{
				$day_of_week = date("N", time() + 86400);
				$text = getTimetableForDayOfWeek($day_of_week, $user, "завтра");
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на неделю" or $input_message == "на неделю")
			{
				$text = "";
				$day_of_week = date("N");
				$day_of_week_time = time();
				for ($i = 0; $i < 7; $i++)
				{
					$text .= "_________________________________\n";
					$text .= getTimetableForDayOfWeek($day_of_week, $user);
					$day_of_week_time += 86400;
					$day_of_week = date("N", $day_of_week_time);
				}				
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на понедельник" or $input_message == "понедельник")
			{
				$text = getTimetableForDayOfWeek(1, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на вторник" or $input_message == "вторник")
			{
				$text = getTimetableForDayOfWeek(2, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на среду" or $input_message == "среда")
			{
				$text = getTimetableForDayOfWeek(3, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на четверг" or $input_message == "четверг")
			{
				$text = getTimetableForDayOfWeek(4, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на пятницу" or $input_message == "пятница")
			{
				$text = getTimetableForDayOfWeek(5, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на субботу" or $input_message == "суббота")
			{
				$text = getTimetableForDayOfWeek(6, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "пары на воскресенье" or $input_message == "воскресенье")
			{
				$text = getTimetableForDayOfWeek(7, $user);
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "сменить группу")
			{
				sendRequestToDB("UPDATE `users` SET group_request = 1 WHERE id = $chat_id");
				$text = "Напишите учебную группу";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "сменить подгруппу")
			{
				sendRequestToDB("UPDATE `users` SET subgroup_request = 1 WHERE id = $chat_id");
				$text = "Введите учебную подгруппу(цифра 1 или цифра 2)";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			elseif ($input_message == "помощь")
			{
				$text = "Доступные команды:\n\n";
				$text .= "- неделя;\n";
				$text .= "- пары на сегодня(сегодня);\n";
				$text .= "- пары на завтра(завтра);\n";
				$text .= "- пары на неделю;\n";
				$text .= "- пары на понедельник(понедельник);\n";
				$text .= "- пары на ...;\n";
				$text .= "- пары на воскресенье(воскресенье);\n";
				$text .= "- сменить группу;\n";
				$text .= "- сменить подгруппу;\n";
				$text .= "- /keyboard(включить клавиатуру);\n";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
			else
			{
				$text = "Данная команда не найдена. Введите команду \"помощь\", чтобы узнать все доступные команды)";
				sendRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text]);
			}
		}
	}
?>