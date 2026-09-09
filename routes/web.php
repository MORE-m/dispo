<?php

use App\Http\Controllers\Administration\AdministrationHubController;
use App\Http\Controllers\Administration\AdvertisingCategoryAdminController;
use App\Http\Controllers\Administration\AdvertisingMediumAdminController;
use App\Http\Controllers\Administration\CatalogHubController;
use App\Http\Controllers\Administration\FieldDefinitionAdminController;
use App\Http\Controllers\Administration\FieldSetAdminController;
use App\Http\Controllers\Administration\FieldSetAssignmentAdminController;
use App\Http\Controllers\AdministrationAccessController;
use App\Http\Controllers\CalculationController;
use App\Http\Controllers\DispoOrderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OverviewController;
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

    Route::get('standardangebote', UnavailableModuleController::class)->defaults('module', 'standard-offers')->name('standard-offers.index');
    Route::get('dispoauftraege', [DispoOrderController::class, 'index'])->name('dispo-orders.index');
    Route::get('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'show'])->name('dispo-orders.show');
    Route::patch('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'update'])->name('dispo-orders.update');
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
    });

    Route::get('admin', AdministrationAccessController::class)->name('admin.access');
});

require __DIR__.'/settings.php';
