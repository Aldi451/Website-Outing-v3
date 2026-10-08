<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/init.php
 * --------------------------------------------------------------------------
 *  Bootstrap aplikasi: dipanggil di BARIS PERTAMA setiap halaman/endpoint.
 *  Urutan: config.php -> database.php -> (helper sudah dimuat config.php)
 * ==========================================================================
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
