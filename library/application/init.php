<?php

// Base
require_once '/var/www/.structure/library/application/api/connect.php';
require_once '/var/www/.structure/library/application/api/variables.php';

// Objects
require_once '/var/www/.structure/library/application/api/objects/Application.php';

// Account
require_once '/var/www/.structure/library/application/api/objects/account/Account.php';
require_once '/var/www/.structure/library/application/api/objects/account/AccountRegistry.php';

// Finance
require_once '/var/www/.structure/library/application/api/objects/finance/PaymentProcessor.php';

// Communication
require_once '/var/www/.structure/library/application/api/objects/communication/LanguageTranslation.php';

// Information
require_once '/var/www/.structure/library/application/api/objects/information/WebsiteKnowledge.php';
require_once '/var/www/.structure/library/application/api/objects/information/InformationPlaceholder.php';

// Session
require_once '/var/www/.structure/library/application/api/objects/session/AccountSession.php';
require_once '/var/www/.structure/library/application/api/objects/session/TwoFactorAuthentication.php';

// Schedulers
require_once '/var/www/.structure/library/application/api/schedulers/refreshTransactions.php';
