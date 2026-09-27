<?php

use App\Http\Controllers\Administration\AdministrationHubController;
use App\Http\Controllers\Administration\AdvertisingCategoryAdminController;
use App\Http\Controllers\Administration\AdvertisingMediumAdminController;
use App\Http\Controllers\Administration\CalculationMethodAdminController;
use App\Http\Controllers\Administration\CatalogHubController;
use App\Http\Controllers\Administration\FieldDefinitionAdminController;
use App\Http\Controllers\Administration\FieldSetAdminController;
use App\Http\Controllers\Administration\FieldSetAssignmentAdminController;
use App\Http\Controllers\Administration\InventoryAdminController;
use App\Http\Controllers\Administration\InventoryMediumRuleAdminController;
use App\Http\Controllers\Administration\PriceListAdminController;
use App\Http\Controllers\Administration\PriceListImportController;
use App\Http\Controllers\AdministrationAccessController;
use App\Http\Controllers\CalculationController;
use App\Http\Controllers\DispoOrderController;
use App\Http\Controllers\E2E\E2EChoiceSnapshotController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\StandardOfferController;
use App\Http\Controllers\UnavailableModuleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', OverviewController::class)->name('dashboard');

    Route::get('kalkulationen', [CalculationController::class, 'index'])->name('calculations.index');
    Route::get('kalkulationen/neu', [CalculationController::class, 'create'])->name('calculations.create');
    Route::post('kalkulationen', [CalculationController::class, 'store'])->name('calculations.store');
    Route::post('kalkulationen/vorschau', [CalculationController::class, 'preview'])->name('calculations.preview');
    Route::post('kalkulationen/budget-vorschlag', [CalculationController::class, 'proposeBudget'])->name('calculations.budget-propose');
    Route::post('kalkulationen/feldschema', [CalculationController::class, 'fieldSchema'])
        ->name('calculations.field-schema');
    Route::get('kalkulationen/{calculation}', [CalculationController::class, 'edit'])->name('calculations.edit');
    Route::put('kalkulationen/{calculation}', [CalculationController::class, 'update'])->name('calculations.update');
    Route::post('kalkulationen/{calculation}/budget-vorschlaege/{proposal}/uebernehmen', [CalculationController::class, 'applyBudget'])
        ->name('calculations.budget-apply');

    Route::get('standardangebote', [StandardOfferController::class, 'index'])->name('standard-offers.index');
    Route::get('standardangebote/neu', [StandardOfferController::class, 'create'])->name('standard-offers.create');
    Route::post('standardangebote', [StandardOfferController::class, 'store'])->name('standard-offers.store');
    Route::post('standardangebote/vorschau', [StandardOfferController::class, 'preview'])->name('standard-offers.preview');
    Route::post('standardangebote/feldschema', [StandardOfferController::class, 'fieldSchema'])
        ->name('standard-offers.field-schema');
    Route::get('standardangebote/{standardOffer}', [StandardOfferController::class, 'show'])->name('standard-offers.show');
    Route::put('standardangebote/{standardOffer}/versionen/{version}', [StandardOfferController::class, 'update'])->name('standard-offers.update');
    Route::post('standardangebote/{standardOffer}/entwurf', [StandardOfferController::class, 'storeDraft'])->name('standard-offers.draft');
    Route::post('standardangebote/{standardOffer}/versionen/{version}/veroeffentlichen', [StandardOfferController::class, 'publish'])->name('standard-offers.publish');
    Route::post('standardangebote/{standardOffer}/versionen/{version}/archivieren', [StandardOfferController::class, 'archive'])->name('standard-offers.archive');
    Route::post('standardangebote/{standardOffer}/versionen/{version}/uebernehmen', [StandardOfferController::class, 'adopt'])->name('standard-offers.adopt');

    Route::get('dispoauftraege', [DispoOrderController::class, 'index'])->name('dispo-orders.index');
    Route::get('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'show'])->name('dispo-orders.show');
    Route::get('dispoauftraege/{dispoOrder}/spotverteilung.xlsx', [DispoOrderController::class, 'exportSpotDistribution'])
        ->name('dispo-orders.export-spot-distribution');
    Route::patch('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'update'])->name('dispo-orders.update');
    Route::put('dispoauftraege/{dispoOrder}/kundenbestaetigung', [DispoOrderController::class, 'updateCustomerConfirmation'])
        ->name('dispo-orders.customer-confirmation.update');
    Route::post('dispoauftraege/{dispoOrder}/uploads/kundenbestaetigung', [DispoOrderController::class, 'uploadCustomerConfirmation'])
        ->name('dispo-orders.uploads.customer-confirmation');
    Route::post('dispoauftraege/{dispoOrder}/uploads', [DispoOrderController::class, 'uploadMaterial'])
        ->name('dispo-orders.uploads.store');
    Route::post('dispoauftraege/{dispoOrder}/uploads/dynamisches-feld', [DispoOrderController::class, 'uploadDynamicField'])
        ->name('dispo-orders.uploads.dynamic-field');
    Route::post('dispoauftraege/{dispoOrder}/uploads/{upload}/archivieren', [DispoOrderController::class, 'archiveUpload'])
        ->name('dispo-orders.uploads.archive');
    Route::get('dispoauftraege/{dispoOrder}/uploads/{upload}/download', [DispoOrderController::class, 'downloadUpload'])
        ->name('dispo-orders.uploads.download');
    Route::get('dispoauftraege/{dispoOrder}/uploads/{upload}/stream', [DispoOrderController::class, 'streamUpload'])
        ->name('dispo-orders.uploads.stream');
    Route::patch('dispoauftraege/{dispoOrder}/positions-angaben', [DispoOrderController::class, 'updatePositionCustoms'])
        ->name('dispo-orders.update-position-customs');
    Route::post('dispoauftraege/{dispoOrder}/sync-calculation-dynamic-fields', [DispoOrderController::class, 'syncCalculationDynamicFields'])
        ->name('dispo-orders.sync-calculation-dynamic-fields');
    Route::post('dispoauftraege/{dispoOrder}/einreichen', [DispoOrderController::class, 'submit'])
        ->name('dispo-orders.submit');
    Route::post('dispoauftraege/{dispoOrder}/genehmigen', [DispoOrderController::class, 'approve'])
        ->name('dispo-orders.approve');
    Route::post('dispoauftraege/{dispoOrder}/ablehnen', [DispoOrderController::class, 'reject'])
        ->name('dispo-orders.reject');
    Route::post('dispoauftraege/{dispoOrder}/status', [DispoOrderController::class, 'transitionOperationalStatus'])
        ->name('dispo-orders.transition-status');
    Route::put('dispoauftraege/{dispoOrder}/positionen/{position}/rechnung-per-ende', [DispoOrderController::class, 'updateInvoiceEndMonths'])
        ->name('dispo-orders.positions.invoice-end');
    Route::post('dispoauftraege/{dispoOrder}/abschliessen', [DispoOrderController::class, 'complete'])
        ->name('dispo-orders.complete');
    Route::post('dispoauftraege/{dispoOrder}/wieder-oeffnen', [DispoOrderController::class, 'reopenCompleted'])
        ->name('dispo-orders.reopen-completed');
    Route::post('dispoauftraege/{dispoOrder}/stornieren', [DispoOrderController::class, 'cancel'])
        ->name('dispo-orders.cancel');
    Route::post('dispoauftraege/{dispoOrder}/rueckfragen', [DispoOrderController::class, 'askSalesInquiry'])
        ->name('dispo-orders.sales-inquiry.ask');
    Route::post('dispoauftraege/{dispoOrder}/rueckfragen/{comment}/antwort', [DispoOrderController::class, 'answerSalesInquiry'])
        ->scopeBindings()
        ->name('dispo-orders.sales-inquiry.answer');
    Route::post('dispoauftraege/{dispoOrder}/kommentare', [DispoOrderController::class, 'addComment'])
        ->name('dispo-orders.comments.store');
    Route::post('dispoauftraege/{dispoOrder}/nachbessern', [DispoOrderController::class, 'startRevision'])
        ->name('dispo-orders.revise');
    Route::get('kalkulationen/{calculation}/dispoauftraege/positionen', [DispoOrderController::class, 'positions'])
        ->name('dispo-orders.positions');
    Route::post('kalkulationen/{calculation}/dispoauftraege', [DispoOrderController::class, 'store'])
        ->name('dispo-orders.store');
    Route::get('auswertungen', UnavailableModuleController::class)->defaults('module', 'reports')->name('reports.index');
    Route::get('stammdaten', UnavailableModuleController::class)->defaults('module', 'master-data')->name('master-data.index');

    Route::middleware(['can:access-administration'])->group(function () {
        Route::get('administration', AdministrationHubController::class)
            ->name('administration.index');
        Route::get('administration/dynamische-felder', [FieldSetAdminController::class, 'home'])
            ->name('administration.dynamic-fields.index');
        Route::get('administration/dynamische-felder/definitionen', [FieldDefinitionAdminController::class, 'index'])
            ->name('administration.dynamic-fields.definitions.index');
        Route::get('administration/dynamische-felder/definitionen/neu', [FieldDefinitionAdminController::class, 'create'])
            ->name('administration.dynamic-fields.definitions.create');
        Route::post('administration/dynamische-felder/definitionen', [FieldDefinitionAdminController::class, 'store'])
            ->name('administration.dynamic-fields.definitions.store');
        Route::get('administration/dynamische-felder/definitionen/{definition}', [FieldDefinitionAdminController::class, 'show'])
            ->name('administration.dynamic-fields.definitions.show');
        Route::put('administration/dynamische-felder/definitionen/{definition}', [FieldDefinitionAdminController::class, 'update'])
            ->name('administration.dynamic-fields.definitions.update');
        Route::post('administration/dynamische-felder/definitionen/{definition}/revisionen', [FieldDefinitionAdminController::class, 'storeRevision'])
            ->name('administration.dynamic-fields.definitions.revisions.store');
        Route::post('administration/dynamische-felder/definitionen/{definition}/deaktivieren', [FieldDefinitionAdminController::class, 'deactivate'])
            ->name('administration.dynamic-fields.definitions.deactivate');
        Route::post('administration/dynamische-felder/definitionen/{definition}/reaktivieren', [FieldDefinitionAdminController::class, 'reactivate'])
            ->name('administration.dynamic-fields.definitions.reactivate');
        Route::delete('administration/dynamische-felder/definitionen/{definition}', [FieldDefinitionAdminController::class, 'destroy'])
            ->name('administration.dynamic-fields.definitions.destroy');
        Route::post('administration/dynamische-felder/definitionen/{definition}/optionen-vorschau', [FieldDefinitionAdminController::class, 'optionsPreview'])
            ->name('administration.dynamic-fields.definitions.options.preview');
        Route::put('administration/dynamische-felder/definitionen/{definition}/optionen', [FieldDefinitionAdminController::class, 'optionsReplace'])
            ->name('administration.dynamic-fields.definitions.options.replace');
        Route::get('administration/dynamische-felder/feldsets', [FieldSetAdminController::class, 'index'])
            ->name('administration.dynamic-fields.field-sets.index');
        Route::get('administration/dynamische-felder/feldsets/neu', [FieldSetAdminController::class, 'create'])
            ->name('administration.dynamic-fields.field-sets.create');
        Route::post('administration/dynamische-felder/feldsets', [FieldSetAdminController::class, 'store'])
            ->name('administration.dynamic-fields.field-sets.store');
        Route::get('administration/dynamische-felder/feldsets/{fieldSet}', [FieldSetAdminController::class, 'show'])
            ->name('administration.dynamic-fields.field-sets.show');
        Route::put('administration/dynamische-felder/feldsets/{fieldSet}', [FieldSetAdminController::class, 'updateMetadata'])
            ->name('administration.dynamic-fields.field-sets.update');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/deaktivieren', [FieldSetAdminController::class, 'deactivate'])
            ->name('administration.dynamic-fields.field-sets.deactivate');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/reaktivieren', [FieldSetAdminController::class, 'reactivate'])
            ->name('administration.dynamic-fields.field-sets.reactivate');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/entwuerfe', [FieldSetAdminController::class, 'createDraft'])
            ->name('administration.dynamic-fields.field-sets.drafts.store');
        Route::get('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}', [FieldSetAdminController::class, 'editVersion'])
            ->name('administration.dynamic-fields.field-sets.versions.edit');
        Route::put('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}', [FieldSetAdminController::class, 'updateDraft'])
            ->name('administration.dynamic-fields.field-sets.versions.update');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/felder', [FieldSetAdminController::class, 'addMembership'])
            ->name('administration.dynamic-fields.field-sets.versions.memberships.store');
        Route::delete('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/felder/{membership}', [FieldSetAdminController::class, 'removeMembership'])
            ->name('administration.dynamic-fields.field-sets.versions.memberships.destroy');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/aktuelle-revisionen', [FieldSetAdminController::class, 'pinCurrentRevisions'])
            ->name('administration.dynamic-fields.field-sets.versions.pin-current');
        Route::get('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/vorschau', [FieldSetAdminController::class, 'preview'])
            ->name('administration.dynamic-fields.field-sets.versions.preview');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/regeln-vorschau', [FieldSetAdminController::class, 'rulesPreview'])
            ->name('administration.dynamic-fields.field-sets.versions.rules.preview');
        Route::put('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/regeln', [FieldSetAdminController::class, 'rulesReplace'])
            ->name('administration.dynamic-fields.field-sets.versions.rules.replace');
        Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/aktivieren', [FieldSetAdminController::class, 'activate'])
            ->name('administration.dynamic-fields.field-sets.versions.activate');

        // DF-3.3a1 JSON-Lifecycle + DF-3.3b Inertia-Admin-UI (PO-33b-2 Dyn-Feld-Admin)
        Route::get('administration/dynamische-felder/assignments', [FieldSetAssignmentAdminController::class, 'index'])
            ->name('administration.dynamic-fields.assignments.index');
        Route::get('administration/dynamische-felder/assignments/neu', [FieldSetAssignmentAdminController::class, 'create'])
            ->name('administration.dynamic-fields.assignments.create');
        Route::get('administration/dynamische-felder/assignments/{assignment}', [FieldSetAssignmentAdminController::class, 'show'])
            ->name('administration.dynamic-fields.assignments.show');
        Route::post('administration/dynamische-felder/assignments', [FieldSetAssignmentAdminController::class, 'store'])
            ->name('administration.dynamic-fields.assignments.store');
        Route::put('administration/dynamische-felder/assignments/{assignment}', [FieldSetAssignmentAdminController::class, 'update'])
            ->name('administration.dynamic-fields.assignments.update');
        Route::post('administration/dynamische-felder/assignments/kontext-vorschau', [FieldSetAssignmentAdminController::class, 'previewContext'])
            ->name('administration.dynamic-fields.assignments.context-preview');
        Route::post('administration/dynamische-felder/assignments/{assignment}/aktivierungs-vorschau', [FieldSetAssignmentAdminController::class, 'previewActivation'])
            ->name('administration.dynamic-fields.assignments.activation-preview');
        Route::post('administration/dynamische-felder/assignments/{assignment}/aktivieren', [FieldSetAssignmentAdminController::class, 'activate'])
            ->name('administration.dynamic-fields.assignments.activate');
        Route::post('administration/dynamische-felder/assignments/{assignment}/deaktivieren', [FieldSetAssignmentAdminController::class, 'deactivate'])
            ->name('administration.dynamic-fields.assignments.deactivate');

        // BL-P2-01a Inventar-Admin-Lifecycle (UX-GATE-D Teilfreigabe; ohne Memberships)
        Route::get('administration/inventare', [InventoryAdminController::class, 'index'])
            ->name('administration.inventories.index');
        Route::get('administration/inventare/neu', [InventoryAdminController::class, 'create'])
            ->name('administration.inventories.create');
        Route::post('administration/inventare', [InventoryAdminController::class, 'store'])
            ->name('administration.inventories.store');
        Route::get('administration/inventare/{inventory}', [InventoryAdminController::class, 'show'])
            ->name('administration.inventories.show');
        Route::put('administration/inventare/{inventory}', [InventoryAdminController::class, 'update'])
            ->name('administration.inventories.update');
        Route::put('administration/inventare/{inventory}/werbemittel-regeln/{rule}', [InventoryMediumRuleAdminController::class, 'update'])
            ->name('administration.inventories.medium-rules.update');
        Route::post('administration/inventare/{inventory}/deaktivierungs-vorschau', [InventoryAdminController::class, 'deactivatePreview'])
            ->name('administration.inventories.deactivate-preview');
        Route::post('administration/inventare/{inventory}/deaktivieren', [InventoryAdminController::class, 'deactivate'])
            ->name('administration.inventories.deactivate');
        Route::post('administration/inventare/{inventory}/reaktivierungs-vorschau', [InventoryAdminController::class, 'reactivatePreview'])
            ->name('administration.inventories.reactivate-preview');
        Route::post('administration/inventare/{inventory}/reaktivieren', [InventoryAdminController::class, 'reactivate'])
            ->name('administration.inventories.reactivate');

        Route::get('administration/preislisten', [PriceListAdminController::class, 'index'])
            ->name('administration.price-lists.index');
        Route::get('administration/preislisten/import', [PriceListImportController::class, 'create'])
            ->name('administration.price-lists.import');
        Route::post('administration/preislisten/import', [PriceListImportController::class, 'upload'])
            ->name('administration.price-lists.import.upload');
        Route::post('administration/preislisten/import/{priceListImport}/pruefen', [PriceListImportController::class, 'validateImport'])
            ->name('administration.price-lists.import.validate');
        Route::post('administration/preislisten/import/{priceListImport}/bestaetigen', [PriceListImportController::class, 'confirm'])
            ->name('administration.price-lists.import.confirm');
        Route::get('administration/preislisten/neu', [PriceListAdminController::class, 'create'])
            ->name('administration.price-lists.create');
        Route::post('administration/preislisten', [PriceListAdminController::class, 'store'])
            ->name('administration.price-lists.store');
        Route::get('administration/preislisten/{priceList}', [PriceListAdminController::class, 'show'])
            ->name('administration.price-lists.show');
        Route::put('administration/preislisten/{priceList}', [PriceListAdminController::class, 'update'])
            ->name('administration.price-lists.update');
        Route::post('administration/preislisten/{priceList}/pruefen', [PriceListAdminController::class, 'inspect'])
            ->name('administration.price-lists.inspect');
        Route::post('administration/preislisten/{priceList}/aktivierungs-vorschau', [PriceListAdminController::class, 'activatePreview'])
            ->name('administration.price-lists.activate-preview');
        Route::post('administration/preislisten/{priceList}/aktivieren', [PriceListAdminController::class, 'activate'])
            ->name('administration.price-lists.activate');
        Route::post('administration/preislisten/{priceList}/archivierungs-vorschau', [PriceListAdminController::class, 'archivePreview'])
            ->name('administration.price-lists.archive-preview');
        Route::post('administration/preislisten/{priceList}/archivieren', [PriceListAdminController::class, 'archive'])
            ->name('administration.price-lists.archive');
        Route::delete('administration/preislisten/{priceList}', [PriceListAdminController::class, 'destroy'])
            ->name('administration.price-lists.destroy');

        // ADV-001b Katalog-Admin (PO-ADV001b-1 UX-GATE-D Teilfreigabe)
        Route::get('administration/katalog', CatalogHubController::class)
            ->name('administration.catalog.index');
        Route::get('administration/katalog/oberkategorien', [AdvertisingCategoryAdminController::class, 'index'])
            ->name('administration.catalog.categories.index');
        Route::get('administration/katalog/oberkategorien/neu', [AdvertisingCategoryAdminController::class, 'create'])
            ->name('administration.catalog.categories.create');
        Route::post('administration/katalog/oberkategorien', [AdvertisingCategoryAdminController::class, 'store'])
            ->name('administration.catalog.categories.store');
        Route::get('administration/katalog/oberkategorien/{category}', [AdvertisingCategoryAdminController::class, 'show'])
            ->name('administration.catalog.categories.show');
        Route::put('administration/katalog/oberkategorien/{category}', [AdvertisingCategoryAdminController::class, 'update'])
            ->name('administration.catalog.categories.update');
        Route::post('administration/katalog/oberkategorien/{category}/deaktivierungs-vorschau', [AdvertisingCategoryAdminController::class, 'deactivatePreview'])
            ->name('administration.catalog.categories.deactivate-preview');
        Route::post('administration/katalog/oberkategorien/{category}/deaktivieren', [AdvertisingCategoryAdminController::class, 'deactivate'])
            ->name('administration.catalog.categories.deactivate');
        Route::post('administration/katalog/oberkategorien/{category}/reaktivieren', [AdvertisingCategoryAdminController::class, 'reactivate'])
            ->name('administration.catalog.categories.reactivate');
        Route::post('administration/katalog/oberkategorien/{category}/berechnungsmethoden-vorschau', [AdvertisingCategoryAdminController::class, 'calculationMethodsPreview'])
            ->name('administration.catalog.categories.calculation-methods-preview');
        Route::put('administration/katalog/oberkategorien/{category}/berechnungsmethoden', [AdvertisingCategoryAdminController::class, 'calculationMethodsReplace'])
            ->name('administration.catalog.categories.calculation-methods');

        Route::get('administration/katalog/werbemittel', [AdvertisingMediumAdminController::class, 'index'])
            ->name('administration.catalog.media.index');
        Route::get('administration/katalog/werbemittel/neu', [AdvertisingMediumAdminController::class, 'create'])
            ->name('administration.catalog.media.create');
        Route::post('administration/katalog/werbemittel', [AdvertisingMediumAdminController::class, 'store'])
            ->name('administration.catalog.media.store');
        Route::get('administration/katalog/werbemittel/{medium}', [AdvertisingMediumAdminController::class, 'show'])
            ->name('administration.catalog.media.show');
        Route::put('administration/katalog/werbemittel/{medium}', [AdvertisingMediumAdminController::class, 'update'])
            ->name('administration.catalog.media.update');
        Route::post('administration/katalog/werbemittel/{medium}/deaktivierungs-vorschau', [AdvertisingMediumAdminController::class, 'deactivatePreview'])
            ->name('administration.catalog.media.deactivate-preview');
        Route::post('administration/katalog/werbemittel/{medium}/deaktivieren', [AdvertisingMediumAdminController::class, 'deactivate'])
            ->name('administration.catalog.media.deactivate');
        Route::post('administration/katalog/werbemittel/{medium}/reaktivieren', [AdvertisingMediumAdminController::class, 'reactivate'])
            ->name('administration.catalog.media.reactivate');
        Route::post('administration/katalog/werbemittel/{medium}/kategorie-wechsel-vorschau', [AdvertisingMediumAdminController::class, 'categoryChangePreview'])
            ->name('administration.catalog.media.category-change-preview');
        Route::post('administration/katalog/werbemittel/{medium}/kategorie-wechseln', [AdvertisingMediumAdminController::class, 'changeCategory'])
            ->name('administration.catalog.media.category-change');
        Route::post('administration/katalog/werbemittel/{medium}/berechnungsmethoden-vorschau', [AdvertisingMediumAdminController::class, 'calculationMethodsPreview'])
            ->name('administration.catalog.media.calculation-methods-preview');
        Route::put('administration/katalog/werbemittel/{medium}/berechnungsmethoden', [AdvertisingMediumAdminController::class, 'calculationMethodsReplace'])
            ->name('administration.catalog.media.calculation-methods');

        Route::get('administration/katalog/berechnungsmethoden', [CalculationMethodAdminController::class, 'index'])
            ->name('administration.catalog.methods.index');
        Route::get('administration/katalog/berechnungsmethoden/{method}', [CalculationMethodAdminController::class, 'show'])
            ->name('administration.catalog.methods.show');
        Route::put('administration/katalog/berechnungsmethoden/{method}', [CalculationMethodAdminController::class, 'update'])
            ->name('administration.catalog.methods.update');
        Route::post('administration/katalog/berechnungsmethoden/{method}/deaktivierungs-vorschau', [CalculationMethodAdminController::class, 'deactivatePreview'])
            ->name('administration.catalog.methods.deactivate-preview');
        Route::post('administration/katalog/berechnungsmethoden/{method}/deaktivieren', [CalculationMethodAdminController::class, 'deactivate'])
            ->name('administration.catalog.methods.deactivate');
        Route::post('administration/katalog/berechnungsmethoden/{method}/reaktivieren', [CalculationMethodAdminController::class, 'reactivate'])
            ->name('administration.catalog.methods.reactivate');
    });

    Route::get('admin', AdministrationAccessController::class)->name('admin.access');
});

// Isolierte Playwright-Server: nur testing + E2E_SERVER=1. Nie Produktion/Route-Cache.
if (app()->environment('testing') && config('app.e2e_server')) {
    Route::middleware(['auth'])->prefix('e2e')->group(function () {
        Route::post('snapshot-choice-option-active', [E2EChoiceSnapshotController::class, 'setOptionActive'])
            ->name('e2e.snapshot-choice-option-active');
        Route::post('snapshot-field-visible', [E2EChoiceSnapshotController::class, 'setFieldVisible'])
            ->name('e2e.snapshot-field-visible');
        Route::post('snapshot-field-rule', [E2EChoiceSnapshotController::class, 'upsertRule'])
            ->name('e2e.snapshot-field-rule');
        Route::post('snapshot-rules-corrupt', [E2EChoiceSnapshotController::class, 'corruptRules'])
            ->name('e2e.snapshot-rules-corrupt');
        Route::get('calculation-choice-value', [E2EChoiceSnapshotController::class, 'choiceValue'])
            ->name('e2e.calculation-choice-value');
        Route::get('dynamic-field-text-value', [E2EChoiceSnapshotController::class, 'textValue'])
            ->name('e2e.dynamic-field-text-value');
        Route::get('dispo-choice-value', [E2EChoiceSnapshotController::class, 'dispoChoiceValue'])
            ->name('e2e.dispo-choice-value');
        Route::get('dispo-positions', [E2EChoiceSnapshotController::class, 'dispoPositions'])
            ->name('e2e.dispo-positions');
        Route::post('dispo-snapshot-choice-options', [E2EChoiceSnapshotController::class, 'replaceDispoChoiceOptions'])
            ->name('e2e.dispo-snapshot-choice-options');
        Route::get('calculation-positions', [E2EChoiceSnapshotController::class, 'positions'])
            ->name('e2e.calculation-positions');
    });
}

require __DIR__.'/settings.php';
