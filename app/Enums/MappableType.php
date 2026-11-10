<?php

namespace App\Enums;

/**
 * Aliasy morph map dla elementów projektu (używane m.in. w fmi_wp_mappings i fmi_activity_log).
 */
enum MappableType: string
{
	case Project = 'project';
	case Page = 'page';
	case Section = 'section';
	case Field = 'field';
	case RepeaterRow = 'repeater_row';
	case Asset = 'asset';
	case ClientAccess = 'client_access';
	case Export = 'export';
	case ReviewRevision = 'review_revision';
	case ReviewThread = 'review_thread';
	case ReviewScreen = 'review_screen';
	case ReviewComment = 'review_comment';
}
