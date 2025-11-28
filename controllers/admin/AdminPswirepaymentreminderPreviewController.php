<?php
// ¡Sin namespace! Los Admin controllers legacy deben estar en el espacio global.
class AdminPswirepaymentreminderPreviewController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        // Este controller se muestra en un <iframe>; devolvemos HTML del email renderizado.
        header('Content-Type: text/html; charset=UTF-8');

        $ctx = Context::getContext();
        $module = Module::getInstanceByName('pswirepaymentreminder');
        if (!$module || !$module->active) {
            die('<p style="padding:20px;color:#a00">Módulo desinstalado/inactivo.</p>');
        }

        $idLang = (int)Tools::getValue('id_lang', (int)$ctx->language->id);
        $idShop = (int)Tools::getValue('id_shop', (int)$ctx->shop->id);
        $lang = new Language($idLang);
        if (!Validate::isLoadedObject($lang)) {
            $lang = $ctx->language;
            $idLang = (int)$lang->id;
        }

        // Poner contexto de tienda (importante en multitienda)
        $prevShopId = (int)$ctx->shop->id;
        if ($prevShopId !== $idShop && $idShop > 0) {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
            $ctx->shop = new Shop($idShop);
        }

        try {
            // === Variables de ejemplo ===
            $hours = (int)Configuration::get(Pswirepaymentreminder::CFG_HOURS, null, null, $idShop);
            if ($hours <= 0) { $hours = 24; }

            $urgTpl = (string)Configuration::get(Pswirepaymentreminder::CFG_URGENCY, $idLang, null, $idShop);
            if ($urgTpl === '') { $urgTpl = $module->l('Reserva de artículos activa durante {hours} horas'); }
            $urgency_badge = str_replace('{hours}', (string)$hours, $urgTpl);

            // Datos bancarios: primero los propios del módulo; si no, fallback a ps_wirepayment
            $owner   = (string)Configuration::get(Pswirepaymentreminder::CFG_BW_OWNER,   null, null, $idShop);
            $details = (string)Configuration::get(Pswirepaymentreminder::CFG_BW_DETAILS, null, null, $idShop);
            $address = (string)Configuration::get(Pswirepaymentreminder::CFG_BW_ADDRESS, null, null, $idShop);

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

            // Rutas y URLs
            $shopName = Configuration::get('PS_SHOP_NAME', $idLang, null, $idShop);
            $shopUrl  = Tools::getShopDomainSsl(true, true);
            $logoPath = (file_exists(_PS_IMG_DIR_.'logo_mail.jpg') ? _PS_IMG_DIR_.'logo_mail.jpg' : _PS_IMG_DIR_.'logo.jpg');
            $shopLogo = $ctx->link->getMediaLink(str_replace(_PS_ROOT_DIR_.'/', '', $logoPath));

            $historyUrl = $ctx->link->getPageLink('history', true, $idLang);
            $guestUrl   = $ctx->link->getPageLink('guest-tracking', true, $idLang);

            $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
            $totalPaid = Tools::displayPrice(99.90, $currency);

            // Mapeo de variables que existen en tu plantilla
            $vars = [
                '{firstname}'           => 'Carlos',
                '{lastname}'            => 'Cliente',
                '{order_name}'          => 'SAMPLE12345',
                '{order_reference}'     => 'SAMPLE12345',
                '{total_paid}'          => $totalPaid,
                '{bankwire_owner}'      => $owner,
                '{bankwire_details}'    => nl2br($details),
                '{bankwire_address}'    => nl2br($address),
                '{history_url}'         => $historyUrl,
                '{guest_tracking_url}'  => $guestUrl,
                '{shop_name}'           => $shopName,
                '{shop_url}'            => $shopUrl,
                '{shop_logo}'           => $shopLogo,
                '{urgency_badge}'       => $urgency_badge,
            ];

            // Localizar plantilla HTML del mail (según ISO)
            $iso = strtolower($lang->iso_code);
            $base = _PS_MODULE_DIR_.$module->name.'/mails/';
            $paths = [
                $base.$iso.'/bankwire_reminder.html',
                $base.'en/bankwire_reminder.html', // fallback
            ];

            $html = '';
            foreach ($paths as $p) {
                if (file_exists($p)) { $html = file_get_contents($p); break; }
            }
            if ($html === '') {
                die('<p style="padding:20px;color:#a00">No se encontró la plantilla de email (mails/'.$iso.'/bankwire_reminder.html).</p>');
            }

            // Inyectar variables
            $html = strtr($html, $vars);

            // Mostrar
            echo $html;
            exit;

        } catch (Throwable $e) {
            http_response_code(500);
            echo '<pre style="padding:20px;color:#a00">Error en preview: '.$e->getMessage().'</pre>';
            exit;

        } finally {
            // Restaurar contexto de tienda
            if ($prevShopId !== $idShop && $idShop > 0) {
                Shop::setContext(Shop::CONTEXT_SHOP, $prevShopId);
                $ctx->shop = new Shop($prevShopId);
            }
        }
    }
}
