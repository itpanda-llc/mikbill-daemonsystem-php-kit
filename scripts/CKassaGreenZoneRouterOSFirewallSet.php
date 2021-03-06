<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/**
 * Тип NAS RouterOS
 * @link https://wiki.mikbill.pro/billing/nas_access_server/mikbillnas
 */
const NAS_TYPE = 'mikrotik';

/** Комментарии */
const ITEM_COMMENT = '_____ckassa-greenzone-auto';

/**
 * Список сетей CKassa
 * @link https://docs.ckassa.ru/doc/shop-api/#green-zone
 */
const NETWORKS = [
    '94.138.149.214/32',
    '94.138.149.128/27',
    '91.142.87.220/32',
    '213.208.182.174/32',
    '77.75.157.168/32',
    '77.75.157.169/32',
    '77.75.159.166/32',
    '77.75.159.170/32',
    '89.111.54.163/32',
    '89.111.54.165/32',
    '185.77.232.26/32',
    '185.77.233.26/32',
    '185.77.232.27/32',
    '185.77.233.27/32',
    '193.186.162.114/32'
];

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/getNas.php';
require_once '../../../autoload.php';

try {
    !is_null($nas = getNas()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($nas as $v)
    try {
        $c = new RouterOS\Client(['host' => $v['nasname'],
            'user' => $v['naslogin'],
            'pass' => $v['naspass']]);

        $filter = $c->query((new RouterOS\Query('/ip/firewall/filter/print'))
            ->where('comment', ITEM_COMMENT))
            ->readAsIterator();

        $nat = $c->query((new RouterOS\Query('/ip/firewall/nat/print'))
            ->where('comment', ITEM_COMMENT))
            ->readAsIterator();

        $addressList = $c->query((new RouterOS\Query('/ip/firewall/address-list/print'))
            ->where('list', ITEM_COMMENT))
            ->readAsIterator();

        if (!empty($filter->current()['.id']))
            for ($filter->rewind(); $filter->valid(); $filter->next())
                $c->query((new RouterOS\Query('/ip/firewall/filter/remove'))
                    ->equal('.id', $filter->current()['.id']));

        if (!empty($nat->current()['.id']))
            for ($nat->rewind(); $nat->valid(); $nat->next())
                $c->query((new RouterOS\Query('/ip/firewall/nat/remove'))
                    ->equal('.id', $nat->current()['.id']));

        if (!empty($addressList->current()['.id']))
            for ($addressList->rewind(); $addressList->valid(); $addressList->next())
                $c->query((new RouterOS\Query('/ip/firewall/address-list/remove'))
                    ->equal('.id', $addressList->current()['.id']));

        $c->query((new RouterOS\Query('/ip/firewall/filter/add'))
            ->equal('action', 'accept')
            ->equal('chain', 'forward')
            ->equal('comment', ITEM_COMMENT)
            ->equal('src-address-list', ITEM_COMMENT)
            ->equal('place-before', '*0'));

        $c->query((new RouterOS\Query('/ip/firewall/filter/add'))
            ->equal('action', 'accept')
            ->equal('chain', 'forward')
            ->equal('comment', ITEM_COMMENT)
            ->equal('dst-address-list', ITEM_COMMENT)
            ->equal('place-before', '*0'));

        $c->query((new RouterOS\Query('/ip/firewall/nat/add'))
            ->equal('action', 'masquerade')
            ->equal('chain', 'srcnat')
            ->equal('comment', ITEM_COMMENT)
            ->equal('dst-address-list', ITEM_COMMENT)
            ->equal('place-before', '*0'));

        foreach (NETWORKS as $network)
            $c->query((new RouterOS\Query('/ip/firewall/address-list/add'))
                ->equal('list', ITEM_COMMENT)
                ->equal('address', $network));

        $c->read();
    } catch (
        RouterOS\Exceptions\BadCredentialsException
        | RouterOS\Exceptions\ConnectException
        | RouterOS\Exceptions\ClientException
        | RouterOS\Exceptions\ConfigException
        | RouterOS\Exceptions\QueryException
        | Exception $e
    ) {
        echo sprintf("%s\n", $e->getMessage());
    }
