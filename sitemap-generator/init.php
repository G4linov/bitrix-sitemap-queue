<?php

/* ==================== /SITEMAP ==================== */
use Bitrix\Main\Loader;

Loader::includeModule('iblock');

// Регистрируем хэндлеры
AddEventHandler('iblock', 'OnAfterIBlockElementAdd',    ['Enisey\\Sitemap\\Hooks','onElementChange']);
AddEventHandler('iblock', 'OnAfterIBlockElementUpdate', ['Enisey\\Sitemap\\Hooks','onElementChange']);
AddEventHandler('iblock', 'OnAfterIBlockElementDelete', ['Enisey\\Sitemap\\Hooks','onElementDelete']);

AddEventHandler('iblock', 'OnAfterIBlockSectionAdd',    ['Enisey\\Sitemap\\Hooks','onSectionChange']);
AddEventHandler('iblock', 'OnAfterIBlockSectionUpdate', ['Enisey\\Sitemap\\Hooks','onSectionChange']);
AddEventHandler('iblock', 'OnAfterIBlockSectionDelete', ['Enisey\\Sitemap\\Hooks','onSectionDelete']);

// Удобный псевдоним (если где-то вызывалась старая нотация)
class_alias('Enisey\\Sitemap\\Hooks', 'Enisey_Sitemap_Hooks');

// ===== Реализация хуков
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/tools/sitemap_hooks.php';

