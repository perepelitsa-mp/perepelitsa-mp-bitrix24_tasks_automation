<?php
// Отключаем сбор статистики
define("NO_KEEP_STATISTIC", "Y");
define("NO_AGENT_STATISTIC","Y");
//define("NOT_CHECK_PERMISSIONS", true);

// Подключаем ядро Битрикс
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable; // ORM-класс для работы с зависимостями шаблонов задач
use CTaskTemplates;

// Подключаем модуль задач
Loader::includeModule('tasks');

/*──────── Исходные данные ────────*/
$rootId  = ADAPTATION_ROOT_TEMPLATE_ID;  // ID корневого шаблона задачи
$userId  = (int)str_replace('user_', '', '{{Пользователь}}');  // ID пользователя, для которого создаются задачи
$starter = SYSTEM_USER_ID;  // ID пользователя, от имени которого создаются задачи

/*───────────────── Авторизация под нужным пользователем для системных уведомлений ───────────────*/
global $USER;
if (!$USER || !$USER->IsAuthorized() || (int)$USER->GetID() !== $starter) {
    $USER = new CUser;
    $USER->Authorize($starter);         
}

/* 1. Получаем дочерние шаблоны через ORM */
$ids   = [$rootId];
$rows  = DependenceTable::getList([
            'select' => ['TEMPLATE_ID'],
            'filter' => ['PARENT_TEMPLATE_ID' => $rootId]
         ])->fetchAll();
foreach ($rows as $row)
    $ids[] = (int)$row['TEMPLATE_ID'];
$ids = array_values(array_unique($ids));
$ids = array_filter($ids, fn($id) => (int)$id !== (int)$rootId);

/**
 * Получение руководителя пользователя
 * @param int $userId ID пользователя
 * @param int $num Уровень руководителя (1 - прямой руководитель)
 * @return int ID руководителя
 */
function getBoss($userId, $num=1){
$userId = intval($userId);
if ($userId > 0) {
    CModule::IncludeModule("intranet");
    $dbUser = CUser::GetList(($by="id"), ($order="asc"), array("ID_EQUAL_EXACT"=>$userId), array("SELECT" => array("UF_*")));
    $arUser = $dbUser->GetNext();
    if ($arUser["UF_FIRST_LEVEL_AGREEMENT"] != null) {
        return $arUser["UF_FIRST_LEVEL_AGREEMENT"];
}

$i = 0;
while ($i < $num) { $i++; $arManagers=CIntranetUtils::GetDepartmentManager($arUser["UF_DEPARTMENT"], $arUser["ID"], true); foreach ($arManagers as $key=> $value)
    {
        $arUser = $value;
        break;
    }
    }
    return $arUser["ID"];
    }
}

/**
 * Получение руководителя с учетом командировок
 * @param int $userId ID пользователя
 * @return int ID руководителя
 */
function getBossIgnoreTrips($userId){
        $boss = \App\User::getBoss($userId);
        $absenceType = \App\User::absenceType($boss['ID']);
        if (\CIntranetUtils::IsUserAbsent($boss['ID']) && $absenceType!==2){ 
            $boss = \App\User::getBoss($boss['ID']);
        }
        return $boss['ID'];
    }

/**
 * Получение второго согласующего
 * @param int $userId ID пользователя
 * @return int ID второго согласующего
 */
function getSecondUserAgreement($userId){
    $getbossId = \App\User::getBoss($userId);
	$firstCheckBossId = getBossIgnoreTrips($getbossId['ID']);
	$activeBossId = $firstCheckBossId;
	$raise_exit = 0;
	while($raise_exit<100){ 
			if (!\CIntranetUtils::IsUserAbsent($activeBossId) || \App\User::absenceType($activeBossId) == 2){break;}
			$raise_exit++;
            $activeBossId = getBossIgnoreTrips($activeBossId);
			if ($activeBossId != $firstCheckBossId){$firstCheckBossId = $activeBossId; continue; }
			if($activeBossId == $firstCheckBossId || $raise_exit>100){break;}
	}
	return $activeBossId;
    }

// Получаем ID руководителя
$idBoss = getBoss($userId);

// Создаем основную задачу из шаблона
$taskResult = CTaskItem::addByTemplate(
    $rootId,
    $starter,
    [
        'RESPONSIBLE_ID' => $userId,  // Ответственный
        'CREATED_BY'     => $starter  // Создатель
    ]
);

// Обрабатываем результат создания задачи
if (is_array($taskResult)) {
    $taskItem = reset($taskResult);
} else {
    $taskItem = $taskResult;
}

// Добавляем руководителя в наблюдатели
$taskItem->update(['AUDITORS' => [$idBoss]]);
$taskData = $taskItem->getData();

// Формируем базовый URL для задач
$baseUrl = SITE_URL . "/company/personal/user/{$userId}/tasks/task/view/";
$taskLinks = [];

// Добавляем ссылку на основную задачу
$taskLinks[] = "🔹 <a href=\"{$baseUrl}{$taskData['ID']}\">Основная задача #{$taskData['ID']}</a>";

// Создаем дочерние задачи из шаблонов
foreach ($ids as $childTemplateId) {
    $childResult = CTaskItem::addByTemplate(
        $childTemplateId,
        $starter,
        [
            'RESPONSIBLE_ID' => $userId,
            'PARENT_ID'      => $taskData['ID'],  // Привязываем к основной задаче
            'CREATED_BY'     => $starter,
        ]
    );
    // Добавляем руководителя в наблюдатели для дочерних задач
    if (is_array($childResult)) {
        foreach ($childResult as $childTask) {
            $childTask->update(['AUDITORS' => [$idBoss]]);
        }
    } elseif ($childResult instanceof CTaskItem) {
        $childResult->update(['AUDITORS' => [$idBoss]]);
    }
}

// Формируем и отправляем уведомление пользователю
$message = "📝 Добро пожаловать в компанию! Тебе назначена <a href=\"{$baseUrl}{$taskData['ID']}\">корпоративная адаптация №{$taskData['ID']}</a>";

\CIMNotify::Add([
    "TO_USER_ID" => $userId,
    "FROM_USER_ID" => $starter, 
    "NOTIFY_TYPE" => IM_NOTIFY_FROM,
    "NOTIFY_MODULE" => "tasks",
    "NOTIFY_MESSAGE" => $message,
    "NOTIFY_MESSAGE_OUT" => strip_tags($message)
]);
