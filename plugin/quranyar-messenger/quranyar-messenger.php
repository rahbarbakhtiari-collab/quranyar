<?php
/**
 * Plugin Name: QuranYar Messenger
 * Description: Modular Eitaa mini app, university messaging and attendance platform.
 * Version: 1.3.1-rc54
 * Requires PHP: 8.0
 * Text Domain: quranyar
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__.'/src/Core/Config.php';
require_once __DIR__.'/src/Core/SchemaRuntime.php';
require_once __DIR__.'/src/Core/TableNames.php';
require_once __DIR__.'/src/Core/TextNormalizer.php';
require_once __DIR__.'/src/Core/CalendarService.php';
require_once __DIR__.'/src/Core/BatchExport.php';
require_once __DIR__.'/src/Core/SupportModule.php';
require_once __DIR__.'/src/Security/SecurityService.php';
require_once __DIR__.'/src/Storage/MemberRepository.php';
require_once __DIR__.'/src/Storage/FamilyRelationshipRepository.php';
require_once __DIR__.'/src/Storage/CampaignRepository.php';
require_once __DIR__.'/src/Storage/UniversityRepository.php';
require_once __DIR__.'/src/Messaging/DeliveryState.php';
require_once __DIR__.'/src/Messaging/TokenStore.php';
require_once __DIR__.'/src/Messaging/MessageGateway.php';
require_once __DIR__.'/src/Messaging/QueueThroughput.php';
require_once __DIR__.'/src/Messaging/QueueService.php';
require_once __DIR__.'/src/Messaging/MessagingModule.php';
require_once __DIR__.'/src/Welcome/WelcomeModule.php';
require_once __DIR__.'/src/Birthday/BirthdayModule.php';
require_once __DIR__.'/src/Members/MemberAppShell.php';
require_once __DIR__.'/src/Members/MemberExperience.php';
require_once __DIR__.'/src/Members/HomeSummaryService.php';
require_once __DIR__.'/src/Members/MobileOtp.php';
require_once __DIR__.'/src/Members/MembersModule.php';
require_once __DIR__.'/src/Admin/AdminModule.php';
require_once __DIR__.'/src/Core/CoreModule.php';
require_once __DIR__.'/src/Core/HealthCenter.php';
require_once __DIR__.'/src/Core/RetentionService.php';
require_once __DIR__.'/src/Core/MigrationLedger.php';
require_once __DIR__.'/src/Core/Installer.php';
require_once __DIR__.'/src/Core/UpgradeCoordinator.php';
require_once __DIR__.'/src/Core/ModuleContract.php';
require_once __DIR__.'/src/Core/ModuleRegistry.php';
require_once __DIR__.'/src/Core/FeatureRegistry.php';
require_once __DIR__.'/src/Core/ProgramAttendanceBoundary.php';
require_once __DIR__.'/src/Core/ModuleManager.php';
require_once __DIR__.'/src/Core/BootstrapFacade.php';

final class QYS_Messenger {
    use QYS_Support_Module;
    use QYS_Messaging_Module;
    use QYS_Welcome_Module;
    use QYS_Birthday_Module;
    use QYS_Members_Module;
    use QYS_Admin_Module;
    use QYS_Core_Module;

    // Compatibility constants. Canonical values live in QYS_Config from C4 onward.
    public const VERSION=QYS_Config::VERSION;
    public const PLUGIN_FILE=__FILE__;
    public const PLUGIN_DIR=__DIR__;
    public const SCHEMA_VERSION=QYS_Config::SCHEMA_VERSION;
    public const UNIVERSITY=QYS_Config::DEFAULT_UNIVERSITY;
    public const UNIVERSITY_NAME=QYS_Config::DEFAULT_UNIVERSITY_NAME;
    public const APP_SEND_ENDPOINT=QYS_Config::APP_SEND_ENDPOINT;
}

require_once __DIR__.'/src/Universities/PortalExperience.php';
require_once __DIR__.'/src/Universities/Multitenant.php';
require_once __DIR__.'/src/Universities/TenantContext.php';
require_once __DIR__.'/src/Universities/PermissionService.php';
require_once __DIR__.'/src/Universities/PortalFeatureService.php';
require_once __DIR__.'/src/Universities/PortalAssetPolicy.php';
require_once __DIR__.'/src/Birthday/CampusBirthday.php';
require_once __DIR__.'/src/Welcome/CampusWelcome.php';
require_once __DIR__.'/src/TargetedMessaging/Targeted.php';
require_once __DIR__.'/src/Programs/ProgramRepository.php';
require_once __DIR__.'/src/Programs/ProgramService.php';
require_once __DIR__.'/src/Programs/ProgramNotifications.php';
require_once __DIR__.'/src/Programs/ProgramsModule.php';
require_once __DIR__.'/src/Attendance/RecurringAttendance.php';
require_once __DIR__.'/src/Attendance/ProgramAttendanceAdapter.php';
require_once __DIR__.'/src/Attendance/Attendance.php';
require_once __DIR__.'/src/SpreadsheetMessaging/Excel.php';

QYS_Bootstrap_Facade::register(__FILE__);
