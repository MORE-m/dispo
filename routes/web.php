<?php

use App\Http\Controllers\Administration\AdministrationHubController;
use App\Http\Controllers\Administration\FieldDefinitionAdminController;
use App\Http\Controllers\Administration\FieldSetAdminController;
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
    Route::get('kalkulationen/{calculation}', [CalculationController::class, 'edit'])->name('calculations.edit');
    Route::put('kalkulationen/{calculation}', [CalculationController::class, 'update'])->name('calculations.update');
    Route::post('kalkulationen/{calculation}/budget-vorschlaege/{proposal}/uebernehmen', [CalculationController::class, 'applyBudget'])
        ->name('calculations.budget-apply');

    Route::get('standardangebote', UnavailableModuleController::class)->defaults('module', 'standard-offers')->name('standard-offers.index');
    Route::get('dispoauftraege', [DispoOrderController::class, 'index'])->name('dispo-orders.index');
    Route::get('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'show'])->name('dispo-orders.show');
    Route::patch('dispoauftraege/{dispoOrder}', [DispoOrderController::class, 'update'])->name('dispo-orders.update');
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

    Route::get('administration', AdministrationHubController::class)
        ->name('administration.index');
    Route::get('administration/dynamische-felder', [FieldSetAdminController::class, 'home'])
        ->name('administration.dynamic-fields.index');
    Route::get('administration/dynamische-felder/definitionen', [FieldDefinitionAdminController::class, 'index'])
        ->name('administration.dynamic-fields.definitions.index');
    Route::get('administration/dynamische-felder/definitionen/{definition}', [FieldDefinitionAdminController::class, 'show'])
        ->name('administration.dynamic-fields.definitions.show');
    Route::post('administration/dynamische-felder/definitionen/{definition}/revisionen', [FieldDefinitionAdminController::class, 'storeRevision'])
        ->name('administration.dynamic-fields.definitions.revisions.store');
    Route::get('administration/dynamische-felder/feldsets', [FieldSetAdminController::class, 'index'])
        ->name('administration.dynamic-fields.field-sets.index');
    Route::get('administration/dynamische-felder/feldsets/{fieldSet}', [FieldSetAdminController::class, 'show'])
        ->name('administration.dynamic-fields.field-sets.show');
    Route::post('administration/dynamische-felder/feldsets/{fieldSet}/entwuerfe', [FieldSetAdminController::class, 'createDraft'])
        ->name('administration.dynamic-fields.field-sets.drafts.store');
    Route::get('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}', [FieldSetAdminController::class, 'editVersion'])
        ->name('administration.dynamic-fields.field-sets.versions.edit');
    Route::put('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}', [FieldSetAdminController::class, 'updateDraft'])
        ->name('administration.dynamic-fields.field-sets.versions.update');
    Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/aktuelle-revisionen', [FieldSetAdminController::class, 'pinCurrentRevisions'])
        ->name('administration.dynamic-fields.field-sets.versions.pin-current');
    Route::get('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/vorschau', [FieldSetAdminController::class, 'preview'])
        ->name('administration.dynamic-fields.field-sets.versions.preview');
    Route::post('administration/dynamische-felder/feldsets/{fieldSet}/versionen/{version}/aktivieren', [FieldSetAdminController::class, 'activate'])
        ->name('administration.dynamic-fields.field-sets.versions.activate');

    Route::get('admin', AdministrationAccessController::class)->name('admin.access');
});

require __DIR__.'/settings.php';
