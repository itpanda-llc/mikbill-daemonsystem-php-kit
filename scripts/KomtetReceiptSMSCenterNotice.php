<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * Логин SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_LOGIN = '***';

/**
 * Пароль SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_PASSWORD = '***';

/**
 * Имя отправителя SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_SENDER = '***';

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения документов */
const RECEIPTS_TABLE = '__komtet_receipts_log';

/**
 * Значение параметра "Состояние" успешно выполненной задачи
 * @link https://kassa.komtet.ru/integration/api
 */
const KOMTET_DONE_STATE = 'done';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsCenter\MessengerSdk;

/**
 * @return array Параметры документов
 */
function getReceipts(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . RECEIPTS_TABLE . "`.`user_id`,
            `" . RECEIPTS_TABLE . "`.`int_id`,
            `" . RECEIPTS_TABLE . "`.`ext_id`,
            IF(
                SUBSTRING(
                    `" . RECEIPTS_TABLE . "`.`contact`,
                    1,
                    1
                ) = '+',
                SUBSTRING(
                    `" . RECEIPTS_TABLE . "`.`contact`,
                    2
                ),
                `" . RECEIPTS_TABLE . "`.`contact`
            ) AS
                `contact`
        FROM
            `" . RECEIPTS_TABLE . "`
        WHERE
            `" . RECEIPTS_TABLE . "`.`update_time` > DATE_SUB(
                NOW(),
                INTERVAL :interval SECOND
            )
                AND
            `" . RECEIPTS_TABLE . "`.`state` = :doneState
                AND
            `" . RECEIPTS_TABLE . "`.`contact` != ''");

    $sth->bindValue(':doneState', KOMTET_DONE_STATE);
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $id Номер документа Комтет Касса
 * @param string $extId Номер документа
 * @return string Текст сообщения
 */
function getMessage(string $id, string $extId): string
{
    return sprintf("Чек https://kassa.komtet.ru/"
        . "receipts?id=%s&external_id=%s",
        $id,
        $extId);
}

try {
    !is_null($receipts = getReceipts()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$center = new MessengerSdk\Center(SMS_CENTER_LOGIN,
    SMS_CENTER_PASSWORD,
    MessengerSdk\Charset::UTF_8,
    MessengerSdk\Fmt::JSON);

$task = (new MessengerSdk\Send\Task)
    ->setSender(SMS_CENTER_SENDER)
    ->setSoc(MessengerSdk\Send\Soc::YES)
    ->setValid(MessengerSdk\Send\Valid::min(1));

foreach ($receipts as $v) {
    $message = getMessage($v['ext_id'], $v['int_id']);

    $task->setMes($message)
        ->setPhones($v['contact']);

    try {
        $j = json_decode($center->request($task));
    } catch (MessengerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        $error = ERROR_TEXT;
    }

    try {
        logMessage($v['user_id'],
            $v['contact'],
            $message,
            (string) ($error ?? $j->error ?? ''));
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    $error = null;
}
