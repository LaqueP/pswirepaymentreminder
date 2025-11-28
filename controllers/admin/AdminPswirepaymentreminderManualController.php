<?php
/**
 * AdminPswirepaymentreminderManualController
 * Envío manual de recordatorios de transferencia.
 */

class AdminPswirepaymentreminderManualController extends ModuleAdminController
{
    public function __construct()
    {
        // Identidad y orden por defecto del listado
        $this->bootstrap  = true;
        $this->list_id    = 'pswpr_manual_orders';
        $this->table      = 'orders';          // alias "a"
        $this->className  = 'Order';
        $this->identifier = 'id_order';
        $this->orderBy    = 'date_add';
        $this->orderWay   = 'DESC';

        parent::__construct();

        $this->lang = false;
        $this->list_no_link = true;
        $this->toolbar_title = $this->l('Recordatorios de transferencia (manual)');

        // Acciones masivas (desplegable inferior nativo)
        $this->bulk_actions = [
            'pswpr_sendreminder' => [
                'text' => $this->l('Enviar recordatorio a seleccionados'),
                'icon' => 'icon-envelope',
            ],
        ];

        // Columnas (los alias usan havingFilter para evitar errores de SQL)
        $this->fields_list = [
            'id_order' => [
                'title'      => $this->l('ID'),
                'align'      => 'text-center',
                'width'      => 50,
                'filter_key' => 'a!id_order',
            ],
            'reference' => [
                'title'      => $this->l('Referencia'),
                'filter_key' => 'a!reference',
            ],
            'customer' => [
                'title'        => $this->l('Cliente'),
                'havingFilter' => true, // CONCAT alias
            ],
            'state_name' => [
                'title'        => $this->l('Estado'),
                'havingFilter' => true, // alias
            ],
            'date_add' => [
                'title'      => $this->l('Fecha pedido'),
                'type'       => 'datetime',
                'filter_key' => 'a!date_add',
            ],
            'total_paid_tax_incl' => [
                'title'      => $this->l('Total'),
                'type'       => 'price',
                'currency'   => true,
                'filter_key' => 'a!total_paid_tax_incl',
            ],
            'shop_name' => [
                'title'        => $this->l('Tienda'),
                'havingFilter' => true, // alias
            ],
        ];

        // Evitar ORDER BY heredados que rompan el listado
        $this->clearListCookies();
    }

    /**
     * Evita ORDER BY/FILTER heredados tipo id_configuration
     */
    protected function clearListCookies(): void
    {
        $c = $this->context->cookie;
        foreach ([$this->list_id, $this->table, 'configuration'] as $base) {
            foreach (['Orderby','Orderway'] as $suffix) {
                $k = $base.$suffix;
                if (isset($c->$k) && (stripos((string)$c->$k, 'configuration') !== false || $c->$k === 'id_configuration')) {
                    unset($c->$k);
                }
            }
        }
        $c->{$this->list_id.'Orderby'} = 'date_add';
        $c->{$this->list_id.'Orderway'} = 'DESC';
        $c->write();
    }

    /**
     * Carga del JS externo y definición de variables JS.
     */
    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        Media::addJsDef([
            'pswprManual' => [
                'listId'         => $this->list_id,                                  // pswpr_manual_orders
                'table'          => $this->table,                                    // orders
                'submitName'     => 'submitBulkpswpr_sendreminder' . $this->table,   // submitBulkpswpr_sendreminderorders
                'controllerName' => $this->controller_name,
                'labels'         => [
                    'send'         => $this->l('Enviar recordatorio (seleccionados)'),
                    'noSel'        => $this->l('No has seleccionado ningún pedido.'),
                    'formNotFound' => $this->l('No se encontró el formulario del listado.'),
                ],
            ],
        ]);

        // JS que inserta el botón en la barra del panel y dispara el POST del bulk
        $this->addJS($this->module->getPathUri() . 'views/js/admin/manual-toolbar.js');
    }

    public function initProcess()
    {
        // Reset de filtros/orden del propio listado cuando se pulsa "reset"
        if (Tools::getValue('reset_filter')) {
            $this->context->cookie->{$this->list_id.'Orderby'} = 'date_add';
            $this->context->cookie->{$this->list_id.'Orderway'} = 'DESC';
            foreach (array_keys($this->fields_list) as $key) {
                $this->context->cookie->__unset($this->list_id.'Filter_'.$key);
                $this->context->cookie->__unset($this->table.'Filter_'.$key);
            }
            $this->context->cookie->write();
        }
        parent::initProcess();
    }

    public function renderList()
    {
        $idLang = (int)$this->context->language->id;

        // === Filtros del módulo (estados a vigilar), por contexto de tienda ===
        $idsStates = [];
        $whereShop = '';
        if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP) {
            $idShop = (int)$this->context->shop->id;
            $jsonStates = Configuration::get(Pswirepaymentreminder::CFG_STATES, null, null, $idShop) ?: '[]';
            $idsStates = array_map('intval', json_decode($jsonStates, true));
            $whereShop = ' AND a.id_shop='.(int)$idShop;
        } else {
            foreach (Shop::getShops(false, null, true) as $s) {
                $jsonStates = Configuration::get(Pswirepaymentreminder::CFG_STATES, null, null, (int)$s) ?: '[]';
                $idsStates = array_unique(array_merge($idsStates, array_map('intval', json_decode($jsonStates, true))));
            }
        }

        if (empty($idsStates)) {
            $this->informations[] = $this->l('No hay estados configurados en el módulo (pestaña de configuración).');
            return parent::renderList();
        }

        $idsStatesSql = implode(',', $idsStates);

        // SELECT/JOINS compactos (alias con havingFilter)
        $this->_select = '
            CONCAT(c.firstname, " ", c.lastname) AS customer,
            osl.name AS state_name,
            s.name  AS shop_name,
            a.id_currency
        ';
        $this->_join = '
            INNER JOIN '._DB_PREFIX_.'customer c ON (c.id_customer = a.id_customer)
            INNER JOIN '._DB_PREFIX_.'order_state_lang osl ON (osl.id_order_state = a.current_state AND osl.id_lang='.(int)$idLang.')
            INNER JOIN '._DB_PREFIX_.'shop s ON (s.id_shop = a.id_shop)
        ';
        $this->_where = ' AND a.current_state IN ('.$idsStatesSql.') '.$whereShop;

        // Orden consistente
        $this->_orderBy  = 'a.date_add';
        $this->_orderWay = 'DESC';
        $this->shopLinkType = 'shop';

        return parent::renderList();
    }

    /**
     * Bulk action invocado por el desplegable nativo o por el botón JS.
     * - Envía recordatorios
     * - Muestra confirmación con conteo y, además, listado de referencias actualizadas.
     */
    public function processBulkPswprSendreminder()
    {
        // Recoger seleccionados de varias fuentes por compatibilidad
        $ids = $this->boxes;
        if (empty($ids)) {
            $ids = Tools::getValue($this->table.'Box', []);
            if (empty($ids)) {
                $ids = Tools::getValue($this->list_id.'Box', []);
            }
        }
        $ids = array_values(array_unique(array_map('intval', (array)$ids)));

        if (empty($ids)) {
            $this->errors[] = $this->l('No has seleccionado ningún pedido.');
            return false;
        }

        /** @var Pswirepaymentreminder $module */
        $module = Module::getInstanceByName('pswirepaymentreminder');
        if (!$module || !$module->active) {
            $this->errors[] = $this->l('No se pudo cargar el módulo.');
            return false;
        }

        // Enviar (el sender se encarga de inyectar variables y cambiar estado si procede)
        $res = $module->sendManualForOrders($ids);

        $sent    = (int)($res['sent'] ?? 0);
        $skipped = (int)($res['skipped'] ?? 0);
        $reason  = trim((string)($res['reason'] ?? ''));

        // === Construir listado de "números de pedido" (referencias) actualizados ===
        // Si el sender devolviese 'sent_ids', lo usamos; si no, fallback a los seleccionados.
        $sentIds = [];
        if (!empty($res['sent_ids']) && is_array($res['sent_ids'])) {
            $sentIds = array_values(array_unique(array_map('intval', $res['sent_ids'])));
        } elseif ($sent > 0) {
            // Desconocemos cuáles concretamente; mejor mostramos todos los seleccionados como referencia.
            $sentIds = $ids;
        }

        $refsText = '';
        if (!empty($sentIds)) {
            $in = implode(',', array_map('intval', $sentIds));
            $rows = Db::getInstance()->executeS('SELECT id_order, reference FROM '._DB_PREFIX_.'orders WHERE id_order IN ('.$in.')');
            if ($rows) {
                // Ordenar por fecha/ID asc opcionalmente: ya da igual, mostramos lista simple
                $refs = [];
                foreach ($rows as $r) {
                    // Formato: #ID (REF)
                    $refs[] = '#'.(int)$r['id_order'].' ('.pSQL($r['reference']).')';
                }
                if (!empty($refs)) {
                    $refsText = implode(', ', $refs);
                }
            }
        }

        // Mensajes al usuario
        if ($sent) {
            $msg = sprintf($this->l('Enviados %d recordatorios. Saltados %d.'), $sent, $skipped);
            if ($reason) {
                $msg .= ' '.$reason;
            }
            $this->confirmations[] = $msg;

            if ($refsText !== '') {
                // Mensaje adicional con las referencias actualizadas
                $this->confirmations[] = $this->l('Pedidos actualizados:').' '.$refsText;
            }
        } else {
            $msg = sprintf($this->l('No se envió ningún recordatorio. Saltados %d.'), $skipped);
            if ($reason) {
                $msg .= ' '.$reason;
            }
            $this->informations[] = $msg;
        }

        return true;
    }

    public function postProcess()
    {
        // Enlace explícito del nombre del submit que añade el JS externo
        if (Tools::isSubmit('submitBulkpswpr_sendreminder'.$this->table)) {
            return $this->processBulkPswprSendreminder();
        }
        parent::postProcess();
    }
}
