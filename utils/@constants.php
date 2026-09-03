<?php

declare(strict_types=1);

// PATHS
// Web-served dirs live under public/ (the web root); everything else stays above it.
const TEMPLATES_DIR = ROOT_DIR . '/templates/';
const CACHE_DIR = ROOT_DIR . '/cache';
const UPLOADS_DIR = ROOT_DIR . '/public/uploads/';
const IMAGES_DIR = ROOT_DIR . '/public/uploads/images/';
const UTILS_DIR = ROOT_DIR . '/utils/';
const OPERATIONS_DIR = ROOT_DIR . '/operations';
const OP_DIR = ROOT_DIR . '/operations/';
// Optional feature modules (see docs/MODULES.md). Absent/empty = core only.
const MODULES_DIR = ROOT_DIR . '/modules';

// Settings & Config Values
// Compatibility constant for modules. Runtime resolution lives in Config.
const TIMEZONE_DEFAULT = 'UTC';
const TIMEZONE_US_LA = 'America/Los_Angeles';
const DEFAULT_TIMEZONE = 'UTC';

// PROJECT RELATED CONSTANTS
// * pages *
const HOMEPAGE = 'homepage';
const LOGIN_PAGE = "login";
// * Page Name *
const PAGE_NAME = "Jellydash";
// * Page Language *
const DEFAULT_LANGUAGE = "en";
const CZECH_LANGUAGE = "cz";
