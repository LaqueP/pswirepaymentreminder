<?php
/**
 * pswirepaymentreminder.php
 *
 * Módulo: recordatorio de transferencia bancaria (ps_wirepayment)
 * - Seleccionar estados a vigilar
 * - Configurar horas de espera
 * - Plantillas de email propias (en /modules/pswirepaymentreminder/mails/)
 * - Texto de urgencia traducible (multilenguaje) y multitienda
 * - Campos propios (por tienda) para Titular, Datos de cuenta/IBAN y Dirección bancaria
 * - Vista previa de email en BO (iframe con selector de idioma y tienda)
 * - Cron por tienda: muestra la URL exacta según el contexto; en "Todas las tiendas" lista todas
 * - Inyección de variables en plantilla con prioridad a la config del módulo (fallback a ps_wirepayment)
 * - Estado posterior al envío (configurable)
 * - Pestaña para envío manual de recordatorios desde el BO
 * - Límite de fecha (hasta la fecha incluida) para la cron por tienda
 *
 * Compatible con PrestaShop 8.x
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Pswirepaymentreminder extends Module
{
    /** Config keys (todas por tienda; urgencia es multilenguaje) */
    const CFG_STATES       = 'PSWPR_STATES';        // json list id_order_state
    const CFG_HOURS        = 'PSWPR_HOURS';         // int
    const CFG_URGENCY      = 'PSWPR_URGENCY';       // multilang
    const CFG_TOKEN        = 'PSWPR_CRON_TOKEN';    // string (hex)
    // Datos bancarios propios del módulo (no traducibles, por tienda)
    const CFG_BW_OWNER     = 'PSWPR_BW_OWNER';      // string
    const CFG_BW_DETAILS   = 'PSWPR_BW_DETAILS';    // string multilínea
    const CFG_BW_ADDRESS   = 'PSWPR_BW_ADDRESS';    // string multilínea
    // Estado al que pasar tras enviar recordatorio (opcional)
    const CFG_AFTER_STATE  = 'PSWPR_AFTER_STATE';   // int
    // Fecha límite superior (hasta la fecha incluida) para cron automática (YYYY-MM-DD, '' = sin límite)
    const CFG_MAX_DATE     = 'PSWPR_MAX_DATE';

    public function __construct()
    {
        $this->name = 'pswirepaymentreminder';
        $this->tab = 'emailing';
        $this->version = '1.3.5';
        $this->author = 'LaqueP';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Wire Payment Reminder');
        $this->description = $this->l('Envía recordatorios de transferencia, con urgencia traducible, multitienda, vista previa, cron por tienda, cambio de estado posterior, envío manual y límite de fecha.');
    }

    /* =======================================
     * Instalación / Desinstalación
     * ======================================= */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $idShop = (int)$this->context->shop->id;

        // Estados / Horas / Estado posterior por defecto (por tienda)
        Configuration::updateValue(self::CFG_STATES, json_encode([]), false, null, $idShop);
        Configuration::updateValue(self::CFG_HOURS, 24, false, null, $idShop);
        Configuration::updateValue(self::CFG_AFTER_STATE, 0, false, null, $idShop);

        // Urgencia multilenguaje con marcador {hours}
        $langs = Language::getLanguages(false);
        $urg = [];
        $defaultUrg = $this->l('Reserva de artículos activa durante {hours} horas');
        foreach ($langs as $l) {
            $urg[(int)$l['id_lang']] = $defaultUrg;
        }
        Configuration::updateValue(self::CFG_URGENCY, $urg, false, null, $idShop);

        // Prefill datos bancarios desde ps_wirepayment si existen (por comodidad)
        $idLangDef = (int)Configuration::get('PS_LANG_DEFAULT');
        $prefOwner   = Configuration::get('PS_WIREPAYMENT_OWNER',   $idLangDef, null, $idShop) ?: Configuration::get('BANK_WIRE_OWNER',   $idLangDef, null, $idShop);
        $prefDetails = Configuration::get('PS_WIREPAYMENT_DETAILS', $idLangDef, null, $idShop) ?: Configuration::get('BANK_WIRE_DETAILS', $idLangDef, null, $idShop);
        $prefAddress = Configuration::get('PS_WIREPAYMENT_ADDRESS', $idLangDef, null, $idShop) ?: Configuration::get('BANK_WIRE_ADDRESS', $idLangDef, null, $idShop);

        Configuration::updateValue(self::CFG_BW_OWNER,   (string)$prefOwner,   false, null, $idShop);
        Configuration::updateValue(self::CFG_BW_DETAILS, (string)$prefDetails, false, null, $idShop);
        Configuration::updateValue(self::CFG_BW_ADDRESS, (string)$prefAddress, false, null, $idShop);

        // Token por tienda
        Configuration::updateValue(self::CFG_TOKEN, bin2hex(random_bytes(16)), false, null, $idShop);

        // Fecha límite por defecto (vacía = sin límite)
        Configuration::updateValue(self::CFG_MAX_DATE, '', false, null, $idShop);

        // SQL (opcional)
        if (!$this->installSql()) {
            return false;
        }

        // Tabs: Preview (oculta) + Manual (visible)
        if (!$this->installTabs()) {
            return false;
        }

        // Hook para inyectar variables cuando se use Mail::send fuera del módulo
        if (!$this->registerHook('actionGetExtraMailTemplateVars')) {
            return false;
        }

        // Asegurar tabs si algo falló
        $this->ensureTabsInstalled();

        return true;
    }

    public function uninstall()
    {
        $this->uninstallTabs();
        $this->uninstallSql();

        // Borrar configuraciones (todas las tiendas)
        Configuration::deleteByName(self::CFG_STATES);
        Configuration::deleteByName(self::CFG_HOURS);
        Configuration::deleteByName(self::CFG_URGENCY);
        Configuration::deleteByName(self::CFG_TOKEN);
        Configuration::deleteByName(self::CFG_BW_OWNER);
        Configuration::deleteByName(self::CFG_BW_DETAILS);
        Configuration::deleteByName(self::CFG_BW_ADDRESS);
        Configuration::deleteByName(self::CFG_AFTER_STATE);
        Configuration::deleteByName(self::CFG_MAX_DATE);

        return parent::uninstall();
    }

    protected function installSql(): bool
    {
        $file = __DIR__ . '/sql/install.sql';
        if (!file_exists($file)) {
            return true;
        }
        $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, file_get_contents($file));
        return Db::getInstance()->execute($sql);
    }

    protected function uninstallSql(): bool
    {
        $file = __DIR__ . '/sql/uninstall.sql';
        if (!file_exists($file)) {
            return true;
        }
        $sql = str_replace('_DB_PREFIX_', _DB_PREFIX_, file_get_contents($file));
        return Db::getInstance()->execute($sql);
    }

    /**
     * Intenta colgar la pestaña manual bajo "Pedidos". Si no existe el parent en esta instalación,
     * hace fallback a "Módulos" para que siempre aparezca.
     */
    protected function getOrdersParentId(): int
    {
        $parentId = (int)Tab::getIdFromClassName('AdminParentOrders');
        if ($parentId) {
            return $parentId;
        }
        // Fallback seguro: bajo el menú de módulos
        return (int)Tab::getIdFromClassName('AdminParentModulesSf');
    }

    protected function installTabs(): bool
    {
        // Tab oculta para AdminPswirepaymentreminderPreview (solo para el iframe)
        $tabPrev = new Tab();
        $tabPrev->active = 0;
        $tabPrev->class_name = 'AdminPswirepaymentreminderPreview';
        $tabPrev->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tabPrev->name[(int)$lang['id_lang']] = 'PS WPR Preview';
        }
        $tabPrev->id_parent = (int)Tab::getIdFromClassName('AdminParentModulesSf');
        $tabPrev->module = $this->name;

        // Tab visible para envío manual (idealmente bajo Pedidos; si no existe, bajo Módulos)
        $tabManual = new Tab();
        $tabManual->active = 1;
        $tabManual->class_name = 'AdminPswirepaymentreminderManual';
        $tabManual->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tabManual->name[(int)$lang['id_lang']] = $this->l('Bank transfer reminders');
        }
        $tabManual->id_parent = $this->getOrdersParentId();
        $tabManual->module = $this->name;
        $tabManual->icon = 'description'; // Material icon name; opcional

        return (bool)$tabPrev->add() && (bool)$tabManual->add();
    }

    protected function uninstallTabs(): bool
    {
        $ok = true;
        foreach (['AdminPswirepaymentreminderPreview', 'AdminPswirepaymentreminderManual'] as $cls) {
            $id = (int)Tab::getIdFromClassName($cls);
            if ($id) {
                $tab = new Tab($id);
                $ok = $ok && (bool)$tab->delete();
            }
        }
        return $ok;
    }

    /** Repara/crea tabs si no existen (por si faltan tras actualizar/copiar) */
    protected function ensureTabsInstalled(): void
    {
        // Preview (oculta)
        if (!(int)Tab::getIdFromClassName('AdminPswirepaymentreminderPreview')) {
            $tab = new Tab();
            $tab->active = 0;
            $tab->class_name = 'AdminPswirepaymentreminderPreview';
            $tab->id_parent = (int)Tab::getIdFromClassName('AdminParentModulesSf');
            $tab->module = $this->name;
            $tab->name = [];
            foreach (Language::getLanguages(false) as $l) {
                $tab->name[(int)$l['id_lang']] = 'PS WPR Preview';
            }
            $tab->add();
        }

        // Manual (visible)
        if (!(int)Tab::getIdFromClassName('AdminPswirepaymentreminderManual')) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = 'AdminPswirepaymentreminderManual';
            $tab->id_parent = $this->getOrdersParentId();
            $tab->module = $this->name;
            $tab->name = [];
            foreach (Language::getLanguages(false) as $l) {
                $tab->name[(int)$l['id_lang']] = $this->l('Bank transfer reminders');
            }
            $tab->add();
        }
    }

    /* =======================================
     * Helpers multitienda
     * ======================================= */

    /** Devuelve el id_shop a usar al LEER valores para pintar el formulario */
    protected function getFormReadShopId(): int
    {
        if (!Shop::isFeatureActive()) {
            return (int)$this->context->shop->id;
        }
        // En "Todas las tiendas" leemos GLOBAL (id_shop = 0)
        if (Shop::getContext() === Shop::CONTEXT_ALL) {
            return 0;
        }
        return (int)$this->context->shop->id;
    }

    /** Actualiza un valor por tienda o por todas, según el contexto */
    protected function updateValueByContext(string $key, $value, bool $isLang = false): void
    {
        if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP) {
            $idShop = (int)$this->context->shop->id;
            Configuration::updateValue($key, $value, false, null, $idShop);
            return;
        }

        if (Shop::getContext() === Shop::CONTEXT_ALL) {
            foreach (Shop::getShops(false, null, true) as $idShop) {
                Configuration::updateValue($key, $value, false, null, (int)$idShop);
            }
            // Guardar también en GLOBAL para lectura en "Todas las tiendas"
            Configuration::updateValue($key, $value, false, null, 0);
            return;
        }

        if (Shop::getContext() === Shop::CONTEXT_GROUP) {
            $idGroup = (int)$this->context->shop->id_shop_group;
            foreach (Shop::getShops(false, $idGroup, true) as $idShop) {
                Configuration::updateValue($key, $value, false, null, (int)$idShop);
            }
        }
    }

    /* =======================================
     * Configuración BO
     * ======================================= */

    public function getContent()
{
    // Asegurar tabs instaladas (autorreparación)
    $this->ensureTabsInstalled();

    $out = '';
    if (Tools::isSubmit('submitPswpr')) {
        // Guardar estados
        $states = Tools::getValue(self::CFG_STATES, []);
        $states = json_encode(array_map('intval', (array)$states));
        $this->updateValueByContext(self::CFG_STATES, $states, false);

        // Guardar horas
        $hours  = (int)Tools::getValue(self::CFG_HOURS, 24);
        $this->updateValueByContext(self::CFG_HOURS, max(1, $hours), false);

        // Estado posterior
        $afterState = (int)Tools::getValue(self::CFG_AFTER_STATE, 0);
        $this->updateValueByContext(self::CFG_AFTER_STATE, $afterState, false);

        // Guardar urgencia multilenguaje (conservando valores no posteados)
        $langs = Language::getLanguages(false);
        $urgValues = [];
        $defaultUrg = $this->l('Reserva de artículos activa durante {hours} horas');
        $readShopId = $this->getFormReadShopId(); // de dónde leer el existente si no llega en POST
        foreach ($langs as $l) {
            $idLang = (int)$l['id_lang'];
            $kPost  = self::CFG_URGENCY . '_' . $idLang;
            $posted = Tools::getValue($kPost, null);
            if ($posted === null) {
                // No llegó en POST: conservar lo guardado (o usar default si vacío)
                $existing = (string)Configuration::get(self::CFG_URGENCY, $idLang, null, $readShopId);
                $urgValues[$idLang] = ($existing !== '' ? $existing : $defaultUrg);
            } else {
                $urgValues[$idLang] = ($posted !== '' ? (string)$posted : $defaultUrg);
            }
        }
        $this->updateValueByContext(self::CFG_URGENCY, $urgValues, true);

        // Guardar datos bancarios propios (no traducibles)
        $bwOwner   = (string)Tools::getValue(self::CFG_BW_OWNER,   null);
        $bwDetails = (string)Tools::getValue(self::CFG_BW_DETAILS, null);
        $bwAddress = (string)Tools::getValue(self::CFG_BW_ADDRESS, null);

        // Si no llegan en POST, conservar existentes
        $read = $this->getFormReadShopId();
        if ($bwOwner === null)   { $bwOwner   = (string)Configuration::get(self::CFG_BW_OWNER,   null, null, $read); }
        if ($bwDetails === null) { $bwDetails = (string)Configuration::get(self::CFG_BW_DETAILS, null, null, $read); }
        if ($bwAddress === null) { $bwAddress = (string)Configuration::get(self::CFG_BW_ADDRESS, null, null, $read); }

        $this->updateValueByContext(self::CFG_BW_OWNER,   $bwOwner,   false);
        $this->updateValueByContext(self::CFG_BW_DETAILS, $bwDetails, false);
        $this->updateValueByContext(self::CFG_BW_ADDRESS, $bwAddress, false);

        // Guardar fecha límite (hasta esa fecha incluida). Formato YYYY-MM-DD, vacío = sin límite.
        $maxDatePost = Tools::getValue(self::CFG_MAX_DATE, null); // null si no viene en POST
        $readShopId  = $this->getFormReadShopId();
        if ($maxDatePost === null) {
            $maxDate = (string)Configuration::get(self::CFG_MAX_DATE, null, null, $readShopId);
        } else {
            $maxDatePost = trim((string)$maxDatePost);
            if ($maxDatePost === '') {
                $maxDate = '';
            } else {
                if (\Validate::isDate($maxDatePost)) {
                    $maxDate = substr($maxDatePost, 0, 10); // normaliza a AAAA-MM-DD
                } else {
                    $maxDate = (string)Configuration::get(self::CFG_MAX_DATE, null, null, $readShopId);
                    $this->warnings[] = $this->l('La fecha límite no tiene un formato válido (AAAA-MM-DD). Se ha ignorado el cambio.');
                }
            }
        }
        $this->updateValueByContext(self::CFG_MAX_DATE, $maxDate, false);

        // Guardar token (solo editable en contexto tienda concreta)
        if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP) {
            $idShopCtx = (int)$this->context->shop->id;
            $token = (string)Tools::getValue(self::CFG_TOKEN, '');
            if (!empty($token)) {
                Configuration::updateValue(self::CFG_TOKEN, preg_replace('/[^a-f0-9]/i', '', $token), false, null, $idShopCtx);
            } elseif (!Configuration::get(self::CFG_TOKEN, null, null, $idShopCtx)) {
                Configuration::updateValue(self::CFG_TOKEN, bin2hex(random_bytes(16)), false, null, $idShopCtx);
            }
        }

        $out .= $this->displayConfirmation($this->l('Configuración actualizada.'));

        // Mostrar advertencias acumuladas (por ejemplo, fecha inválida)
        if (!empty($this->warnings)) {
            foreach ($this->warnings as $w) {
                $out .= $this->displayWarning($w);
            }
        }
    }

    return $out . $this->renderForm();
}


    protected function renderForm()
{
    $idShopRead = $this->getFormReadShopId(); // 0 si All shops, id tienda si contexto tienda

    // Estados de pedido
    $orderStates = OrderState::getOrderStates($this->context->language->id);
    $options = [];
    foreach ($orderStates as $s) {
        $options[] = [
            'id_option' => (int)$s['id_order_state'],
            'name'      => $s['name']
        ];
    }

    // CSS inline para ajustar tamaños/anchos en el BO
    $inlineCss = '<style>
        #content select[name="'.self::CFG_STATES.'[]"]{ min-height:260px; }
        #content input[name^="'.self::CFG_URGENCY.'["],
        #content input[name^="'.self::CFG_URGENCY.'_"]{ max-width:none; width:100%; }
        #content input[name="'.self::CFG_BW_OWNER.'"],
        #content textarea[name="'.self::CFG_BW_DETAILS.'"],
        #content textarea[name="'.self::CFG_BW_ADDRESS.'"]{ max-width:none; width:100%; }
    </style>';

    // Form principal
    $fields_form = [
        'form' => [
            'legend' => ['title' => $this->l('Ajustes de recordatorio')],
            'input'  => [
                // CSS inline
                ['type' => 'free', 'name' => 'PSWPR_INLINE_CSS'],
                [
                    'type'     => 'select',
                    'label'    => $this->l('Estados a vigilar'),
                    'name'     => self::CFG_STATES . '[]',
                    'multiple' => true,
                    'size'     => 12,
                    'options'  => [
                        'query' => $options,
                        'id'    => 'id_option',
                        'name'  => 'name'
                    ],
                    'desc'     => $this->l('Se enviará el recordatorio si el pedido permanece en cualquiera de estos estados.'),
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('Horas de espera'),
                    'name'  => self::CFG_HOURS,
                    'class' => 'fixed-width-sm',
                    'desc'  => $this->l('Número de horas desde la creación del pedido para enviar el recordatorio.'),
                ],
                [
                    'type'  => 'date', // si tu BO no pinta datepicker, usar 'text' + 'class' => 'datepicker'
                    'label' => $this->l('Fecha límite (cron)'),
                    'name'  => self::CFG_MAX_DATE,
                    'class' => 'fixed-width-lg',
                    'desc'  => $this->l('La cron solo revisará pedidos creados hasta esta fecha (incluida). AAAA-MM-DD. Déjalo vacío para no limitar.'),
                ],
                [
                    'type'  => 'select',
                    'label' => $this->l('Estado tras enviar el recordatorio'),
                    'name'  => self::CFG_AFTER_STATE,
                    'options' => [
                        'query' => array_merge([['id_option'=>0,'name'=>$this->l('No cambiar')]], $options),
                        'id'    => 'id_option',
                        'name'  => 'name'
                    ],
                    'desc'  => $this->l('Opcional. Si se define, el pedido cambiará a este estado tras enviar el recordatorio.'),
                ],
                // Campo multilenguaje (fluido)
                [
                    'type'   => 'text',
                    'label'  => $this->l('Mensaje de urgencia (traducible)'),
                    'name'   => self::CFG_URGENCY,
                    'lang'   => true,
                    'desc'   => $this->l('Usa {hours} como marcador que será reemplazado por el valor configurado.'),
                ],
                // === Campos de datos bancarios propios del módulo (por tienda, no traducibles) ===
                [
                    'type'  => 'text',
                    'label' => $this->l('Titular de la cuenta'),
                    'name'  => self::CFG_BW_OWNER,
                    'desc'  => $this->l('Se usará en el email y la vista previa. Si lo dejas vacío, se intentará leer del módulo ps_wirepayment.'),
                ],
                [
                    'type'  => 'textarea',
                    'label' => $this->l('Datos de la cuenta / IBAN'),
                    'name'  => self::CFG_BW_DETAILS,
                    'rows'  => 3,
                    'desc'  => $this->l('Puedes usar varias líneas (IBAN, BIC, banco). Si está vacío, fallback a ps_wirepayment.'),
                ],
                [
                    'type'  => 'textarea',
                    'label' => $this->l('Dirección bancaria'),
                    'name'  => self::CFG_BW_ADDRESS,
                    'rows'  => 3,
                    'desc'  => $this->l('Dirección física del banco. Si está vacío, fallback a ps_wirepayment.'),
                ],
            ],
            'submit' => ['title' => $this->l('Guardar')],
        ],
    ];

    // === BLOQUE TOKEN + CRON URL ===
    $cronPanelHtml = $this->buildCronPanelHtml((int)$this->context->shop->id);

    $fields_form['form']['input'][] = [
        'type'  => 'free',
        'label' => $this->l('Tareas programadas (Cron)'),
        'name'  => 'PSWPR_CRON_BLOCK',
        'desc'  => $this->l('Usa estas URLs en tu cron. Cada tienda tiene su propio token.'),
    ];

    // Cuadro informativo de la CRON
    $cronInfoHtml = $this->buildCronInfoHtml();
    $fields_form['form']['input'][] = [
        'type'  => 'free',
        'label' => $this->l('Información sobre la CRON'),
        'name'  => 'PSWPR_CRON_INFO',
    ];

    // === BLOQUE PREVIEW (iframe) ===
    $previewHtml = $this->buildPreviewPanelHtml((int)$this->context->shop->id);

    $fields_form['form']['input'][] = [
        'type'  => 'free',
        'label' => $this->l('Vista previa'),
        'name'  => 'PSWPR_PREVIEW',
        'desc'  => $this->l('Previsualización con datos de ejemplo y tu mensaje de urgencia.'),
    ];

    // === Link directo a la pestaña de envío manual ===
    $manualLink = $this->context->link->getAdminLink('AdminPswirepaymentreminderManual', true, [], ['reset_filter' => 1]);
    $manualBlock = '<a class="btn btn-default" href="'.htmlspecialchars($manualLink).'" target="_blank">
  <i class="icon-envelope"></i> '.$this->l('Abrir envío manual').'</a>';

    $fields_form['form']['input'][] = [
        'type'  => 'free',
        'label' => $this->l('Envío manual'),
        'name'  => 'PSWPR_MANUAL_LINK',
        'desc'  => $manualBlock,
    ];

    // Helper
    $helper = new HelperForm();
    $helper->module = $this;
    $helper->name_controller = $this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
    $helper->default_form_language = (int)$this->context->language->id; // idioma del empleado
    $helper->id_language = (int)$this->context->language->id;
    $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
    $helper->title = $this->displayName;
    $helper->show_toolbar = false;
    $helper->submit_action = 'submitPswpr';

    // Asegura el switcher de idiomas visible
    $helper->languages = [];
    foreach (Language::getLanguages(false) as $lang) {
        $helper->languages[] = [
            'id_lang'    => (int)$lang['id_lang'],
            'iso_code'   => $lang['iso_code'],
            'name'       => $lang['name'],
            'is_default' => (int)$lang['id_lang'] === (int)$helper->default_form_language ? 1 : 0,
        ];
    }

    // Valores para pintar
    $helper->fields_value['PSWPR_INLINE_CSS'] = $inlineCss;

    $statesJson = (string)Configuration::get(self::CFG_STATES, null, null, $idShopRead);
    $helper->fields_value[self::CFG_STATES.'[]'] = json_decode($statesJson ?: '[]', true);

    $helper->fields_value[self::CFG_HOURS]       = (int)Configuration::get(self::CFG_HOURS, null, null, $idShopRead);
    $helper->fields_value[self::CFG_AFTER_STATE] = (int)Configuration::get(self::CFG_AFTER_STATE, null, null, $idShopRead);

    // Fecha límite
    $helper->fields_value[self::CFG_MAX_DATE] =
        (string)Configuration::get(self::CFG_MAX_DATE, null, null, $idShopRead);

    // Urgencia multilenguaje
    $defaultUrg = $this->l('Reserva de artículos activa durante {hours} horas');
    $helper->fields_value[self::CFG_URGENCY] = [];
    foreach (Language::getLanguages(false) as $l) {
        $idLang = (int)$l['id_lang'];
        // Preferir POST si existe
        $postKey = self::CFG_URGENCY.'_'.$idLang;
        $posted  = Tools::getValue($postKey, null);
        if ($posted !== null) {
            $val = (string)$posted;
        } else {
            $val = (string)Configuration::get(self::CFG_URGENCY, $idLang, null, $idShopRead);
        }
        if ($val === '') { $val = $defaultUrg; }
        $helper->fields_value[self::CFG_URGENCY][$idLang] = $val;
    }

    // Datos bancarios propios
    $helper->fields_value[self::CFG_BW_OWNER]   = (string)Configuration::get(self::CFG_BW_OWNER,   null, null, $idShopRead);
    $helper->fields_value[self::CFG_BW_DETAILS] = (string)Configuration::get(self::CFG_BW_DETAILS, null, null, $idShopRead);
    $helper->fields_value[self::CFG_BW_ADDRESS] = (string)Configuration::get(self::CFG_BW_ADDRESS, null, null, $idShopRead);

    // Campo token solo si contexto tienda concreta (editable). En "todas", se muestra tabla en panel.
    if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP) {
        $fields_form['form']['input'][] = [
            'type'  => 'text',
            'label' => $this->l('Token de cron (esta tienda)'),
            'name'  => self::CFG_TOKEN,
            'class' => 'fixed-width-xxl',
            'desc'  => $this->l('Puedes regenerarlo manualmente (hex). Cambiarlo obliga a actualizar tu cron externo.'),
        ];
        $helper->fields_value[self::CFG_TOKEN] = (string)Configuration::get(self::CFG_TOKEN, null, null, (int)$this->context->shop->id);
    }

    // Free blocks (cron panel + preview + manual link + cron info)
    $helper->fields_value['PSWPR_CRON_BLOCK']  = $cronPanelHtml;
    $helper->fields_value['PSWPR_PREVIEW']     = $previewHtml;
    $helper->fields_value['PSWPR_CRON_INFO']   = $cronInfoHtml;  // <-- ahora se inyecta el HTML del cuadro informativo
    $helper->fields_value['PSWPR_MANUAL_LINK'] = ''; // contenido ya va en desc

    return $helper->generateForm([$fields_form]);
}


    /**
     * Panel Cron:
     * - Contexto tienda: SOLO su URL
     * - "Todas las tiendas": tabla con todas
     */
    protected function buildCronPanelHtml(int $idShopCtx): string
    {
        $link = $this->context->link;

        if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP) {
            $token = Configuration::get(self::CFG_TOKEN, null, null, $idShopCtx);
            if (!$token) {
                $token = bin2hex(random_bytes(16));
                Configuration::updateValue(self::CFG_TOKEN, $token, false, null, $idShopCtx);
            }
            $cronUrl = $link->getModuleLink($this->name, 'cron', ['token' => $token, 'id_shop' => $idShopCtx], true);

            $html = '<div class="panel">
                <div class="panel-heading"><i class="icon-cogs"></i> '.$this->l('Cron de esta tienda').'</div>
                <div class="form-group">
                    <label class="control-label col-lg-2">'.$this->l('URL de cron').'</label>
                    <div class="col-lg-10">
                        <input type="text" class="form-control" readonly value="'.htmlspecialchars($cronUrl).'"/>
                        <p class="help-block">'.$this->l('Ejemplo cron:').' <code>*/15 * * * * curl -fsS "'.htmlspecialchars($cronUrl).'" -m 20</code></p>
                    </div>
                </div>
            </div>';

            return $html;
        }

        // "Todas las tiendas": listar todas
        $shops = Shop::getShops(false, null, true);
        $rows = '';
        foreach ($shops as $idShop) {
            $shopObj = new Shop((int)$idShop);
            if (!Validate::isLoadedObject($shopObj)) {
                continue;
            }
            $token = Configuration::get(self::CFG_TOKEN, null, null, (int)$idShop);
            if (!$token) {
                $token = bin2hex(random_bytes(16));
                Configuration::updateValue(self::CFG_TOKEN, $token, false, null, (int)$idShop);
            }
            $cronUrl = $link->getModuleLink($this->name, 'cron', ['token' => $token, 'id_shop' => (int)$idShop], true);

            $rows .= '<tr>
                <td>'.(int)$idShop.'</td>
                <td>'.Tools::safeOutput($shopObj->name).'</td>
                <td><code>'.htmlspecialchars($token).'</code></td>
                <td style="word-break:break-all;"><a href="'.htmlspecialchars($cronUrl).'" target="_blank">'.htmlspecialchars($cronUrl).'</a></td>
                <td><code>*/15 * * * * curl -fsS "'.htmlspecialchars($cronUrl).'" -m 20</code></td>
            </tr>';
        }

        $html = '<div class="panel">
            <div class="panel-heading"><i class="icon-cogs"></i> '.$this->l('Crons por tienda (contexto: Todas las tiendas)').'</div>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>'.$this->l('ID Tienda').'</th>
                        <th>'.$this->l('Nombre').'</th>
                        <th>'.$this->l('Token').'</th>
                        <th>'.$this->l('URL').'</th>
                        <th>'.$this->l('Ejemplo CRON').'</th>
                    </tr>
                </thead>
                <tbody>'.$rows.'</tbody>
            </table>
            </div>
            <p class="help-block">'.$this->l('Cada tienda tiene su token y URL propios. Programa una tarea por tienda.').'</p>
        </div>';

        return $html;
    }

    /**
     * Panel de vista previa del email con selector de idioma y tienda (si MT activo).
     * Apunta al controller AdminPswirepaymentreminderPreview para renderizar el HTML del email.
     */
    protected function buildPreviewPanelHtml(int $idShopCtx): string
    {
        $languages = Language::getLanguages(false);
        $previewLinkBase = $this->context->link->getAdminLink('AdminPswirepaymentreminderPreview');
        $defaultLangId = (int)$this->context->language->id;

        // Selector de tienda si multitienda
        $shopSelectHtml = '';
        if (Shop::isFeatureActive()) {
            $shops = Shop::getShops(true, null, true);
            $shopSelectHtml .= '<div class="col-lg-3">
                <label>'.$this->l('Tienda para vista previa').'</label>
                <select id="pswpr-preview-shop" class="form-control">';
            foreach ($shops as $idShop) {
                $s = new Shop((int)$idShop);
                $sel = ($idShopCtx == $idShop) ? ' selected' : '';
                $shopSelectHtml .= '<option value="'.(int)$idShop.'"'.$sel.'>'.Tools::safeOutput($s->name).'</option>';
            }
            $shopSelectHtml .= '</select></div>';
        }

        $html = '<div class="panel">
            <div class="panel-heading"><i class="icon-eye-open"></i> '.$this->l('Vista previa de email').'</div>
            <div class="row">
              <div class="col-lg-3">
                <label>'.$this->l('Idioma de vista previa').'</label>
                <select id="pswpr-preview-lang" class="form-control">';
        foreach ($languages as $lang) {
            $html .= '<option value="'.(int)$lang['id_lang'].'">'.Tools::safeOutput($lang['name']).' ('.$lang['iso_code'].')</option>';
        }
        $html .= '</select>
              </div>'.
              $shopSelectHtml .
              '<div class="col-lg-3">
                <label>&nbsp;</label><br/>
                <button type="button" id="pswpr-refresh-preview" class="btn btn-default">
                  <i class="icon-refresh"></i> '.$this->l('Actualizar vista previa').'
                </button>
              </div>
            </div>
            <div style="margin-top:15px;border:1px solid #ddd;">
              <iframe id="pswpr-preview-frame"
                      src="'.htmlspecialchars($previewLinkBase.'&id_lang='.$defaultLangId.'&id_shop='.$idShopCtx).'"
                      style="width:100%;height:800px;border:0;background:#fff;"></iframe>
            </div>
            <script>
              (function(){
                var selLang = document.getElementById("pswpr-preview-lang");
                var selShop = document.getElementById("pswpr-preview-shop");
                var btn = document.getElementById("pswpr-refresh-preview");
                var frame = document.getElementById("pswpr-preview-frame");
                var base = "'.addslashes($previewLinkBase).'";
                function refresh(){
                  var lang = selLang && selLang.value ? selLang.value : "'.$defaultLangId.'";
                  var shop = selShop && selShop.value ? selShop.value : "'.$idShopCtx.'";
                  frame.src = base + "&id_lang=" + lang + "&id_shop=" + shop + "&_ts=" + Date.now();
                }
                if (btn) btn.addEventListener("click", refresh);
              })();
            </script>
          </div>';

        return $html;
    }
    protected function buildCronInfoHtml(): string
{
    // Enlace a la pantalla de envío manual (para aclarar que allí NO aplica la restricción de 48h)
    $manualLink = $this->context->link->getAdminLink('AdminPswirepaymentreminderManual', true, [], ['reset_filter' => 1]);

    // Pequeño CSS para el callout
    $css = '<style>
      .pswpr-callout{border-left:4px solid #25B9D7;background:#f8fbfd;padding:12px 15px;margin:10px 0;border-radius:3px;}
      .pswpr-callout h4{margin:0 0 6px 0;font-weight:600;color:#0f5b6a;}
      .pswpr-callout ul{margin:6px 0 0 18px;}
      .pswpr-callout li{margin:4px 0;}
    </style>';

    $html = $css.'
    <div class="pswpr-callout">
      <h4><i class="icon-time"></i> '.$this->l('Cómo filtra ahora la CRON los pedidos').'</h4>
      <ul>
        <li>'.$this->l('Solo revisa pedidos creados en las últimas 48 horas.').'</li>
        <li>'.$this->l('Si el cliente tiene cualquier otro pedido en las últimas 48 horas (misma tienda), no se enviará el recordatorio.').'</li>
        <li>'.$this->l('Se mantienen tus reglas: estados vigilados y horas de espera configuradas.').'</li>
        <li>'.$this->l('Este filtrado aplica únicamente a la ejecución automática por CRON.').'</li>
        <li>'.$this->l('El envío manual no aplica la ventana de 48 h. Puedes usarlo desde:').'
          <a href="'.htmlspecialchars($manualLink).'" target="_blank">'.$this->l('Envío manual de recordatorios').'</a>.
        </li>
      </ul>
    </div>';

    return $html;
}


    /* =======================================
     * Hook: inyección de variables para email
     * ======================================= */

    /**
     * Inyecta variables adicionales cuando se envía la plantilla 'bankwire_reminder'
     * desde cualquier flujo que use Mail::send.
     * Prioriza CFG_BW_* del módulo y hace fallback a ps_wirepayment si están vacías.
     */
    public function hookActionGetExtraMailTemplateVars(array $params)
    {
        if (empty($params['template']) || $params['template'] !== 'bankwire_reminder') {
            return;
        }
        if (empty($params['id_order'])) {
            return;
        }

        $order = new Order((int)$params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $idLang = (int)$order->id_lang;
        $idShop = (int)$order->id_shop;

        $hours = (int)Configuration::get(self::CFG_HOURS, null, null, $idShop);
        $urgTpl = (string)Configuration::get(self::CFG_URGENCY, $idLang, null, $idShop);
        if (!$urgTpl) {
            $urgTpl = $this->l('Reserva de artículos activa durante {hours} horas');
        }
        $urgency = str_replace('{hours}', (string)$hours, $urgTpl);

        // 1) Intentar datos propios del módulo
        $owner   = (string)Configuration::get(self::CFG_BW_OWNER,   null, null, $idShop);
        $details = (string)Configuration::get(self::CFG_BW_DETAILS, null, null, $idShop);
        $address = (string)Configuration::get(self::CFG_BW_ADDRESS, null, null, $idShop);

        // 2) Fallback a ps_wirepayment (multi idioma)
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

        $params['extra_template_vars'] = array_merge($params['extra_template_vars'] ?? [], [
            '{bankwire_owner}'   => $owner,
            '{bankwire_details}' => nl2br($details),
            '{bankwire_address}' => nl2br($address),
            '{urgency_badge}'    => $urgency,
        ]);

        return $params;
    }

    /* =======================================
     * API pública para cron/sender
     * ======================================= */

    /**
     * Procesa y envía los recordatorios pendientes (invocado por el front controller cron).
     * @return array {'sent'=>int,'skipped'=>int,'reason'=>string}
     */
    public function sendDueReminders(): array
    {
        require_once __DIR__ . '/classes/ReminderSender.php';
        $sender = new \Pswpr\ReminderSender($this);

        $idShop = (int)$this->context->shop->id;
        $after  = (int)Configuration::get(self::CFG_AFTER_STATE, null, null, $idShop);
        $sender->setAfterState($after);

        return $sender->process();
    }

    /**
     * Envío manual para un conjunto de pedidos (IDs).
     * Realiza misma lógica (filtros, envío y cambio de estado por tienda).
     */
    public function sendManualForOrders(array $ids): array
    {
        require_once __DIR__ . '/classes/ReminderSender.php';
        $sender = new \Pswpr\ReminderSender($this);
        // En manual, el sender aplicará el AFTER_STATE de cada tienda del pedido.
        return $sender->process(array_map('intval', $ids));
    }
}
