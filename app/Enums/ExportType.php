<?php

namespace App\Enums;

enum ExportType: string
{
	case Json = 'json';
	case WordPressSync = 'wordpress_sync';
}
