<?php

namespace App\Enums;

enum ExportStatus: string
{
	case Pending = 'pending';
	case Running = 'running';
	case Completed = 'completed';
	case Failed = 'failed';
}
