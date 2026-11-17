<?php

namespace Tests\Config;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Router\Router;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The routes of the dialog forms: a form for a new entry posts to its save route with the id -1, and a
 * screen that names an entry by something that is not a number is a page that does not exist.
 */
class RoutesTest extends CIUnitTestCase
{
    private function handlerOf(string $verb, string $uri): string
    {
        $routes = service('routes', true);
        $routes->loadRoutes();
        $routes->setHTTPVerb(strtolower($verb));

        $request = service('incomingrequest', null, false);
        $request->setMethod(strtoupper($verb));

        $router = new Router($routes, $request);
        $router->handle($uri);

        return basename(str_replace('\\', '/', $router->controllerName())) . '::' . $router->methodName();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function newEntryForms(): array
    {
        return [
            'a price list'               => ['price_lists/save/-1', 'Price_lists::postSave'],
            'a receipt voucher'          => ['receipt_vouchers/save/-1', 'Receipt_vouchers::postSave'],
            'a revenue category'         => ['revenue_categories/save/-1', 'Revenue_categories::postSave'],
            'a tax definition'           => ['tax_definitions/save/-1', 'Tax_definitions::postSave'],
            'a custom field'             => ['custom_fields/save/-1', 'Custom_fields::postSave'],
            'an item brand'              => ['item_brands/save/-1', 'Item_brands::postSave'],
            'a customer classification'  => ['customer_classifications/save/-1', 'Customer_classifications::postSave'],
            'a received cheque'          => ['accounting_cheques/save/received/-1', 'AccountingCheques::postSaveCheque'],
            'a paid cheque'              => ['accounting_cheques/save/paid/-1', 'AccountingCheques::postSaveCheque'],
            'a cheque book'              => ['accounting_cheques/save_book/-1', 'AccountingCheques::postSaveBook'],
        ];
    }

    #[DataProvider('newEntryForms')]
    public function testHandle_RoutesTheNewEntryOfADialogFormToItsSave(string $uri, string $handler): void
    {
        $this->assertSame($handler, $this->handlerOf('POST', $uri), $uri);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function screensOfOneEntry(): array
    {
        return [
            'a branch'      => ['branches/view/abc'],
            'a role'        => ['roles/view/abc'],
            'a treasury'    => ['accounting_treasuries/view/abc'],
            'a fiscal year' => ['accounting_periods/view/abc'],
            'an account'    => ['accounting_chart/view/abc'],
            'a cost center' => ['accounting_cost_centers/view/abc'],
            'a number too big for an id' => ['branches/view/9999999999999999999'],
            'a purchase row too big for an id' => ['purchases/row/99999999999999999999'],
        ];
    }

    #[DataProvider('screensOfOneEntry')]
    public function testHandle_RefusesAnEntryThatIsNotANumber(string $uri): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->handlerOf('GET', $uri);
    }
}
