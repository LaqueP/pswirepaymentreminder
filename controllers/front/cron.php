<?php
class PswirepaymentreminderCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        // Parámetros
        $idShop = (int)Tools::getValue('id_shop', (int)Context::getContext()->shop->id);
        $token  = (string)Tools::getValue('token');

        // Validar tienda (multi-tienda)
        if (Shop::isFeatureActive()) {
            $shop = new Shop($idShop);
            if (!Validate::isLoadedObject($shop)) {
                header('HTTP/1.1 400 Bad Request');
                die('Invalid id_shop');
            }
        } else {
            $idShop = (int)Context::getContext()->shop->id;
        }

        // Forzar contexto a la tienda solicitada
        $prevContextType = Shop::getContext();
        $prevShopId = Shop::getContextShopID();
        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);

        try {
            // Token por tienda
            $shopToken = Configuration::get(Pswirepaymentreminder::CFG_TOKEN, null, null, $idShop);
            if (!$token || !$shopToken || !hash_equals($shopToken, preg_replace('/[^a-f0-9]/i', '', $token))) {
                header('HTTP/1.1 403 Forbidden');
                die('Invalid token');
            }

            // Ejecutar envío para esa tienda
            $result = $this->module->sendDueReminders();

            header('Content-Type: application/json');
            echo json_encode([
                'module' => $this->module->name,
                'shop'   => $idShop,
                'result' => $result,
                'time'   => date(DATE_ATOM),
            ]);
        } finally {
            // Restaurar contexto
            Shop::setContext($prevContextType, $prevShopId);
        }
        exit;
    }

    public function display()
    {
        // No plantilla
    }
}
