<?php
namespace Pswpr;

use Configuration;
use Context;
use Currency;
use Customer;
use Db;
use Language;
use Link;
use Mail;
use Order;
use OrderHistory;
use Shop;
use Tools;
use Validate;

class ReminderSender
{
    /** @var \Pswirepaymentreminder */
    protected $module;

    protected $afterState = 0;

    public function __construct(\Pswirepaymentreminder $module)
    {
        $this->module = $module;
    }

    public function setAfterState(int $idState)
    {
        $this->afterState = max(0, $idState);
    }

    /**
     * Procesa envíos:
     * - Si $idsOrders es null → modo automático (usa horas/estados/fecha límite)
     * - Si es array → modo manual (solo esos IDs; respeta lógica de tienda/estado posterior)
     */
    public function process(array $idsOrders = null): array
    {
        $sent = 0; $skipped = 0; $reason = '';

        if ($idsOrders === null) {
            // Automático: buscar pedidos por estados + tiempo + fecha límite (hasta)
            $idShop = (int)Context::getContext()->shop->id;
            $hours  = (int)Configuration::get(\Pswirepaymentreminder::CFG_HOURS, null, null, $idShop);
            $statesJson = Configuration::get(\Pswirepaymentreminder::CFG_STATES, null, null, $idShop) ?: '[]';
            $idsStates = array_map('intval', json_decode($statesJson, true));

            if (empty($idsStates)) {
                return ['sent'=>0,'skipped'=>0,'reason'=>'No states configured'];
            }

            // NUEVO: límite superior por fecha (hasta la fecha incluida)
            $maxDate = trim((string)Configuration::get(\Pswirepaymentreminder::CFG_MAX_DATE, null, null, $idShop));
            $dateClause = '';
            if ($maxDate !== '' && \Validate::isDate($maxDate)) {
                $dateClause = " AND o.date_add <= '".pSQL(substr($maxDate, 0, 10))." 23:59:59'";
                // Alternativa equivalente:
                // $dateClause = " AND o.date_add < DATE_ADD('".pSQL(substr($maxDate, 0, 10))."', INTERVAL 1 DAY)";
            }

            $sql = 'SELECT o.id_order
                    FROM '._DB_PREFIX_.'orders o
                    WHERE o.current_state IN ('.implode(',', $idsStates).')
                      AND o.id_shop='.(int)$idShop.'
                      AND TIMESTAMPDIFF(HOUR, o.date_add, NOW()) >= '.(int)$hours.
                      $dateClause.'
                    ORDER BY o.date_add ASC
                    LIMIT 500';

            $idsOrders = array_map('intval', array_column(Db::getInstance()->executeS($sql) ?: [], 'id_order'));
        }

        foreach ($idsOrders as $idOrder) {
            $order = new Order((int)$idOrder);
            if (!Validate::isLoadedObject($order)) { $skipped++; continue; }

            $ctx = Context::getContext();
            $ctx->language = new Language((int)$order->id_lang);
            $ctx->shop     = new Shop((int)$order->id_shop);
            $ctx->currency = new Currency((int)$order->id_currency);
            $ctx->link     = new Link();

            // Horas/estado posterior por tienda
            $hours      = (int)Configuration::get(\Pswirepaymentreminder::CFG_HOURS, null, null, (int)$order->id_shop);
            $afterState = $this->afterState;
            if (!$afterState) {
                $afterState = (int)Configuration::get(\Pswirepaymentreminder::CFG_AFTER_STATE, null, null, (int)$order->id_shop);
            }

            // Variables de plantilla (todas, incluyendo bankwire + urgency)
            $tplVars = $this->buildTemplateVars($order, $hours);

            // Envío
            $template = 'bankwire_reminder';
            $subject  = $this->module->l('Recordatorio de transferencia bancaria', 'ReminderSender');
            $to       = (new Customer((int)$order->id_customer))->email;

            $ok = Mail::Send(
                (int)$order->id_lang,
                $template,
                $subject,
                $tplVars,
                $to,
                null, // to name
                null, // from
                null, // from name
                null, // file attachment
                null, // mode smtp
                _PS_MODULE_DIR_.$this->module->name.'/mails/', // mail dir
                false, // die
                (int)$order->id_shop, // id_shop
                null, // bcc
                (int)$order->id_order // id_order → útil para hooks/log
            );

            if ($ok) {
                $sent++;
                // Cambio de estado si procede
                if ($afterState > 0) {
                    $this->updateOrderState($order, $afterState);
                }
            } else {
                $skipped++;
            }
        }

        return ['sent'=>$sent,'skipped'=>$skipped,'reason'=>$reason];
    }

    /** Construye todas las variables de la plantilla, incluyendo bankwire + urgency */
    protected function buildTemplateVars(Order $order, int $hours): array
    {
        $ctx    = Context::getContext();
        $idLang = (int)$order->id_lang;
        $idShop = (int)$order->id_shop;

        // Urgencia
        $urgTpl = (string)Configuration::get(\Pswirepaymentreminder::CFG_URGENCY, $idLang, null, $idShop);
        if ($urgTpl === '') {
            $urgTpl = $this->module->l('Reserva de artículos activa durante {hours} horas', 'ReminderSender');
        }
        $urgency = str_replace('{hours}', (string)$hours, $urgTpl);

        // Bankwire (preferir config del módulo; fallback a ps_wirepayment)
        $owner   = (string)Configuration::get(\Pswirepaymentreminder::CFG_BW_OWNER,   null, null, $idShop);
        $details = (string)Configuration::get(\Pswirepaymentreminder::CFG_BW_DETAILS, null, null, $idShop);
        $address = (string)Configuration::get(\Pswirepaymentreminder::CFG_BW_ADDRESS, null, null, $idShop);

        if ($owner === '') {
            $owner = (string)(Configuration::get('PS_WIREPAYMENT_OWNER', $idLang, null, $idShop)
                ?: Configuration::get('BANK_WIRE_OWNER', $idLang, null, $idShop));
        }
        if ($details === '') {
            $details = (string)(Configuration::get('PS_WIREPAYMENT_DETAILS', $idLang, null, $idShop)
                ?: Configuration::get('BANK_WIRE_DETAILS', $idLang, null, $idShop));
        }
        if ($address === '') {
            $address = (string)(Configuration::get('PS_WIREPAYMENT_ADDRESS', $idLang, null, $idShop)
                ?: Configuration::get('BANK_WIRE_ADDRESS', $idLang, null, $idShop));
        }

        // Otras variables típicas
        $customer = new Customer((int)$order->id_customer);
        $shopName = Configuration::get('PS_SHOP_NAME', $idLang, null, $idShop);
        $historyUrl = $ctx->link->getPageLink('history', true, $idLang, [], false, $idShop);
        $guestUrl   = $ctx->link->getPageLink('guest-tracking', true, $idLang, [], false, $idShop);
        $shopUrl    = $ctx->link->getBaseLink($idShop);

        // Total formateado
        $totalPaid = Tools::displayPrice($order->total_paid, (int)$order->id_currency);

        return [
            '{firstname}'          => $customer->firstname,
            '{lastname}'           => $customer->lastname,
            '{order_name}'         => $order->reference,
            '{total_paid}'         => $totalPaid,

            // URLs habituales
            '{history_url}'        => $historyUrl,
            '{guest_tracking_url}' => $guestUrl,
            '{shop_url}'           => $shopUrl,
            '{shop_name}'          => $shopName,

            // Nuestras variables clave
            '{urgency_badge}'      => $urgency,
            '{bankwire_owner}'     => $owner,
            '{bankwire_details}'   => nl2br($details),
            '{bankwire_address}'   => nl2br($address),
        ];
    }

    protected function updateOrderState(Order $order, int $idState): void
    {
        if ($idState <= 0) { return; }
        $history = new OrderHistory();
        $history->id_order = (int)$order->id;
        $history->id_employee = (int)Context::getContext()->employee->id ?: 0;
        $history->changeIdOrderState((int)$idState, (int)$order->id);
        $history->addWithemail(true);
    }
}
