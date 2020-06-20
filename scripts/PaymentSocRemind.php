<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки Челябинвестбанк
 * @link https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk
 */
require_once '../../chelinvest-acquirer-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки Челябинвестбанк
 * @link https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk
 */
use Panda\Chelinvest\AcquirerSDK\Acquirer;
use Panda\Chelinvest\AcquirerSDK\Register;
use Panda\Chelinvest\AcquirerSDK\Payment;
use Panda\Chelinvest\AcquirerSDK\Exception\ClientException as
    AcquirerSDKException;

/**
 * Логин Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_USER = 'CHELINVEST_USER';

/**
 * Пароль Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_PASSWORD = 'CHELINVEST_PASSWORD';

/**
 * URL-адрес для возврата после оплаты
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_RETURN_URL = 'CHELINVEST_RETURN_URL';

/**
 * Подключение библиотеки СМСЦентр
 * @link https://github.com/itpanda-llc/smsc-sender-php-sdk
 */
require_once '../../smsc-sender-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки СМСЦентр
 * @link https://github.com/itpanda-llc/smsc-sender-php-sdk
 */
use Panda\SMSC\SenderSDK\Sender;
use Panda\SMSC\SenderSDK\Format;
use Panda\SMSC\SenderSDK\Message;
use Panda\SMSC\SenderSDK\Valid;
use Panda\SMSC\SenderSDK\Charset;
use Panda\SMSC\SenderSDK\Exception\ClientException as
    SenderSDKException;

/**
 * Логин СМСЦентр
 * @link https://smsc.ru/user/
 */
const SMSC_LOGIN = 'SMSC_LOGIN';

/**
 * Пароль СМСЦентр
 * @link https://smsc.ru/passwords/
 */
const SMSC_PASSWORD = 'SMSC_PASSWORD';

/**
 * Имя отправителя СМСЦентр
 * @link https://smsc.ru/api/
 */
const SMSC_SENDER = 'SMSC_SENDER';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения заказов */
const ORDERS_TABLE = 'orders_log';

/** Наименование сервиса */
const SERVICE_NAME = 'Домашний интернет';

/** Наименование денежной единицы */
const CURRENCY_NAME = 'руб';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

/**
 * @return SimpleXMLElement Объект конфигурационного файла
 */
function getConfig(): SimpleXMLElement
{
    static $sxe;

    if (!isset($sxe)) {
        try {
            $sxe = new SimpleXMLElement(CONFIG,
                LIBXML_ERR_NONE,
                true);
        } catch (Exception $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $sxe;
}

/**
 * @return PDO Обработчик запросов к БД
 */
function getConnect(): PDO
{
    static $dbh;

    if (!isset($dbh)) {
        $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8",
            getConfig()->parameters->mysql->host,
            getConfig()->parameters->mysql->dbname);

        try {
            $dbh = new PDO($dsn,
                getConfig()->parameters->mysql->username,
                getConfig()->parameters->mysql->password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $dbh;
}

/**
 * @return array|null Параметры клиентов и платежей
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `users`.`user`,
            `users`.`uid`,
            `users`.`sms_tel`,
            ROUND(
                ABS(
                    (
                        `users`.`deposit`
                            +
                        (
                            IF (
                                `users`.`credit_unlimited` = 1,
                                `users`.`credit`,
                                0
                            )
                        )
                            +
                        `packets`.`razresh_minus`
                    )
                        -
                    (
                        (
                            `packets`.`fixed_cost`
                                +
                            (
                                CASE
                                    WHEN (
                                        (
                                            `users`.`real_ip` = 0
                                        )
                                            OR
                                        (
                                            `users`.`real_ipfree` = 1
                                        )
                                    )
                                        THEN
                                            0
                                    WHEN (
                                        `users`.`real_price` = 0
                                    )
                                        THEN
                                            `packets`.`real_price`
                                    ELSE
                                        `users`.`real_price`
                                END
                            )
                        )
                            *
                        (
                            1 - `users`.`fixed_cost` / 100
                        )
                    )
                ), 2
            ) AS
                `amount`,
            CONCAT_WS(
                ' ',
                DATE_FORMAT(
                    (
                        NOW() + INTERVAL :interval DAY
                    ), '%e'
                ),
                CASE
                    WHEN (
                        (
                            @month :=
                                (
                                    DATE_FORMAT(
                                        (
                                            NOW() + INTERVAL :interval DAY
                                        ), '%c'
                                    )
                                )
                        ) = '1'
                    )
                        THEN
                            'января'
                    WHEN (
                        @month = '2'
                    )
                        THEN
                            'февраля'
                    WHEN (
                        @month = '3'
                    )
                        THEN
                            'марта'
                    WHEN (
                        @month = '4'
                    )
                        THEN
                            'апреля'
                    WHEN (
                        @month = '5'
                    )
                        THEN
                            'мая'
                    WHEN (
                        @month = '6'
                    )
                        THEN
                            'июня'
                    WHEN (
                        @month = '7'
                    )
                        THEN
                            'июля'
                    WHEN (
                        @month = '8'
                    )
                        THEN
                            'августа'
                    WHEN (
                        @month = '9'
                    )
                        THEN
                            'сентября'
                    WHEN (
                        @month = '10'
                    )
                        THEN
                            'октября'
                    WHEN (
                        @month = '11'
                    )
                        THEN
                            'ноября'
                    WHEN (
                        @month = '12'
                    )
                        THEN
                            'декабря'
                END
            ) AS
                `date`,
            CONCAT_WS(
                ' // ',
                (
                    CONCAT(
                        SUBSTRING(
                            `users`.`fio`, 1,
                            (
                                LOCATE(
                                    ' ',
                                    `users`.`fio`
                                ) - 1
                            )
                        ),
                        ' ',
                        SUBSTRING(
                            @name :=
                            (
                                SUBSTRING(
                                    `users`.`fio`,
                                    @locate :=
                                    (
                                        LOCATE(
                                            ' ',
                                            SUBSTRING(
                                                `users`.`fio`, 1,
                                                (
                                                    LOCATE(
                                                        ' ',
                                                        `users`.`fio`
                                                    ) + 1
                                                )
                                            )
                                        ) + 1
                                    )
                                )
                            ), 1, 1
                        ), '.',
                        IF (
                            @name != @patronymic :=
                            (
                                SUBSTRING(
                                    SUBSTRING(
                                        `users`.`fio`,
                                        @locate
                                    ),
                                    LOCATE(
                                        ' ',
                                        SUBSTRING(
                                            `users`.`fio`,
                                            @locate
                                        )
                                    ) + 1
                                )
                            ),
                            CONCAT(
                                ' ',
                                SUBSTRING(
                                    @patronymic, 1, 1
                                ), '.'
                            ), ''
                        )
                    )
                ),
                CONCAT(
                    REPLACE(
                        `lanes`.`lane`, '.', ''
                    ),
                    ', ',
                    IF(
                        `users`.`app` = '',
                        `lanes_houses`.`house`,
                        CONCAT(
                            `lanes_houses`.`house`,
                            ', ',
                            `users`.`app`
                        )
                    )
                )
            ) AS
                `description`,
            CONCAT(
                '" . SERVICE_NAME . "',
                ' (Л/СЧ N',
                `users`.`user`,
                ')'
            ) AS
                `product`
        FROM
            `users`
        LEFT JOIN
            `lanes_houses`
                ON
                    `lanes_houses`.`houseid` = `users`.`houseid`
        LEFT JOIN
            `lanes`
                ON
                    `lanes`.`laneid` = `lanes_houses`.`laneid`
        LEFT JOIN
            `packets`
                ON
                    `packets`.`gid` = `users`.`gid`
        WHERE
            `packets`.`fixed` = 11
                AND
            `users`.`blocked` = 0
                AND
            (
                `users`.`deposit`
                    +
                IF (
                    `users`.`credit_unlimited` = 1,
                    `users`.`credit`,
                    0
                )
                    +
                `packets`.`razresh_minus`
            )
                <
            (
                (
                    `packets`.`fixed_cost`
                        +
                    (
                        CASE
                            WHEN (
                                (
                                    `users`.`real_ip` = 0
                                )
                                    OR
                                (
                                    `users`.`real_ipfree` = 1
                                )
                            )
                                THEN
                                    0
                            WHEN (
                                `users`.`real_price` = 0
                            )
                                THEN
                                    `packets`.`real_price`
                            ELSE
                                `users`.`real_price`
                        END
                    )
                )
                    *
                (
                    1 - `users`.`fixed_cost` / 100
                )
            )
                AND
            `users`.`date_abonka` = DATE_FORMAT((NOW() + INTERVAL :interval DAY), '%e')
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''");
    
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/** Добавление таблицы для ведения заказов */
function addTable(): void
{
    getConnect()->exec("
        CREATE TABLE IF NOT EXISTS
            `" . ORDERS_TABLE . "` (
                `id` INT AUTO_INCREMENT,
                `user_id` VARCHAR(128) NOT NULL,
                `order_id` VARCHAR(128) NULL DEFAULT NULL,
                `ext_id` VARCHAR(128) NULL DEFAULT NULL,
                `order_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `order_price` DECIMAL(10,2) NOT NULL,
                PRIMARY KEY (`id`)
            )
            ENGINE = InnoDB
            CHARSET=utf8
            COLLATE utf8_general_ci");
}

/**
 * @param string $userId ID пользователя
 * @param string $orderPrice Стоимость заказа
 */
function logOrder(string $userId, string $orderPrice): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `" . ORDERS_TABLE . "` (
                `user_id`,
                `order_price`
            )
        VALUES (
            :userId,
            :orderPrice
        )");

    $sth->bindParam(':userId', $userId, PDO::PARAM_INT);
    $sth->bindParam(':orderPrice', $orderPrice);

    $sth->execute();
}

/**
 * @param string $userId ID пользователя
 */
function setOrderId(string $userId): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . ORDERS_TABLE . "`
        SET
            `" . ORDERS_TABLE . "`.`order_id`
                =
        CONCAT(
            DATE_FORMAT(
                NOW(
                ), '%y%m'
            ),
            `" . ORDERS_TABLE . "`.`id`
        )
        WHERE
            `" . ORDERS_TABLE . "`.`order_time` > DATE_SUB(
                NOW(
                ), INTERVAL 10 SECOND
            )
                AND
            `" . ORDERS_TABLE . "`.`user_id` = :userId
                AND
            `" . ORDERS_TABLE . "`.`order_id` IS NULL
                AND
            `" . ORDERS_TABLE . "`.`ext_id` IS NULL");

    $sth->bindParam(':userId', $userId, PDO::PARAM_INT);

    $sth->execute();
}

/**
 * @param string $userId ID пользователя
 * @return string|null Номер заказа
 */
function getOrderId(string $userId): ?string
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        SELECT
            IF(
                `" . ORDERS_TABLE . "`.`order_id` IS NULL,
                CONCAT(
                    DATE_FORMAT(
                        NOW(
                        ), '%y%m'
                    ),
                    `" . ORDERS_TABLE . "`.`id`
                ),
                `" . ORDERS_TABLE . "`.`order_id`
            ) AS
                `" . ORDERS_TABLE . "`
        FROM
            `" . ORDERS_TABLE . "`
        WHERE
            `" . ORDERS_TABLE . "`.`order_time` > DATE_SUB(
                NOW(
                ), INTERVAL 10 SECOND
            )
                AND
            `" . ORDERS_TABLE . "`.`user_id` = :userId
                AND
            `" . ORDERS_TABLE . "`.`order_id` IS NOT NULL
                AND
            `" . ORDERS_TABLE . "`.`ext_id` IS NULL");

    $sth->bindParam(':userId', $userId, PDO::PARAM_INT);

    $sth->execute();

    $result = $sth->fetch(PDO::FETCH_COLUMN);

    return ($result !== '') ? $result : null;
}

/**
 * @param string $extId Номер заказа Челябинвестбанк
 * @param string $orderId Номер заказа
 */
function updateOrder(string $extId, string $orderId): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . ORDERS_TABLE . "`
        SET
            `" . ORDERS_TABLE . "`.`ext_id` = :extId
        WHERE
            `" . ORDERS_TABLE . "`.`order_id` = :orderId");

    $sth->bindParam(':extId', $extId);
    $sth->bindParam(':orderId', $orderId);

    $sth->execute();
}

/**
 * @param string $account Аккаунт
 * @param string $amount Размер платежа
 * @param string $date Дата
 * @param string $url URL-адрес страницы оплаты
 * @return string Текст сообщения
 */
function getMessage(string $account,
                    string $amount,
                    string $date,
                    string $url): string
{
    return sprintf("Рекомендуемый платеж по счету #%s:"
        . " %s %s. до %s. Удобная оплата: %s",
        $account,
        $amount,
        CURRENCY_NAME,
        $date,
        $url);
}

/**
 * @param string $uId ID пользователя
 * @param string $phone Номер телефона
 * @param string $text Текст сообщения
 * @param string $errorText Текст ошибки
 */
function logMessage(string $uId,
                    string $phone,
                    string $text,
                    string $errorText): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `sms_logs` (
                `sms_type_id`,
                `uid`,
                `sms_phone`,
                `sms_text`,
                `sms_error_text`
            )
        VALUES (
            0,
            :uId,
            :phone,
            :text,
            :errorText
        )");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':phone', $phone);
    $sth->bindParam(':text', $text);
    $sth->bindParam(':errorText', $errorText);

    $sth->execute();
}

try {
    /** Получение параметров клиентов и платежей */
    !is_null($clients = getClients()) || exit;

    /** Добавление таблицы для ведения заказов */
    addTable();
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Acquirer $acquirer Экземпляр Челябинвестбанк Эквайер */
$acquirer = new Acquirer(CHELINVEST_USER, CHELINVEST_PASSWORD);

/** @var Sender $sender Экземпляр отправителя СМСЦентр */
$sender = new Sender(SMSC_LOGIN, SMSC_PASSWORD, Format::JSON);

/** @var array $v Параметры клиента и платежа */
foreach ($clients as $v) {
    try {
        /** Начало транзакции */
        getConnect()->beginTransaction();
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        /** Запись заказа */
        logOrder($v['uid'], $v['amount']);

        /** Подготовление заказа */
        setOrderId($v['uid']);

        /** @var string $orderId Номер заказа */
        $orderId = getOrderId($v['uid']);
    } catch (PDOException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }

    /** @var Register $register Заказ */
    $register = new Register(CHELINVEST_RETURN_URL,
        $orderId, $v['description']);

    /** Добавление позиции */
    $register->addProduct($v['product'],
        1, (int) ((float) $v['amount'] * 100), '0');

    try {
        /** @var stdClass $j Ответ Челябинвестбанк Эквайер */
        $j = json_decode($acquirer->request($register));
    } catch (AcquirerSDKException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    try {
        /** Обновление информации о заказе */
        updateOrder($j->orderId, $orderId);

        /** Фиксация транзакции */
        getConnect()->commit();
    } catch (PDOException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }

    /** @var string $url URL-адрес страницы оплаты */
    $url = Payment::getURL($j->orderId);

    $message = getMessage($v['user'], $v['amount'], $v['date'], $url);

    /** @var Message $notice Сообщение */
    $notice = new Message(SMSC_SENDER, $message, $v['sms_tel']);

    /** Установка признака soc-сообщения */
    $notice->setSoc()

        /** Установка параметра "Срок "жизни" сообщения" */
        ->setValid(Valid::min(1))

        /** Установка параметра "Кодировка сообщения" */
        ->setCharset(Charset::UTF_8);

    try {
        /** @var stdClass $j Ответ СМСЦентр */
        $j = json_decode($sender->request($notice));
    } catch (SenderSDKException $e) {

        /** @var string $error Текст ошибки */
        $error = ERROR_TEXT;
    }

    try {
        /** Запись сообщения в БД */
        logMessage($v['uid'], $v['sms_tel'],
            $message, $error ?? $j->error ?? '');
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    unset($error);
}