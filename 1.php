<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();

// Load the .env file
$loginLink = getenv('LOGIN_LINK');
$logoutLink = getenv('LOGOUT_LINK');
$afterLoginLink = getenv('AFTER_LOGIN_LINK');
$remoteDriverLink = getenv('REMOTE_DRIVER_LINK');
$uploadCourseLink = getenv('UPLOAD_COURSE_LINK');
$csvPath = __DIR__ . DIRECTORY_SEPARATOR . getenv('CSV_PATH');

// List of results
$createResult = [];
$createResult['succeeded'] = [];
$createResult['failed'] = [];

// Start Google Chrome
$chromeOptions = new ChromeOptions();
$runHeadless = (bool)getenv('RUN_HEADLESS');
if (true === $runHeadless) {
    $chromeOptions->addArguments(['--headless']);
    $chromeOptions->addArguments(['--disable-gpu']);
}
$chromeOptions->addArguments(['--window-size=1200,1080']);

$chromeCapabilities = DesiredCapabilities::chrome();
$chromeCapabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);
$driver = RemoteWebDriver::create($remoteDriverLink, $chromeCapabilities);

// Login
try {
    $driver->get($loginLink);

    $driver->wait(10, 100)->until(
        WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('username'))
    );
} catch (NoSuchElementException $e) {
    echo 'Element not found';
    echo $e->getMessage();

} catch (TimeoutException $e) {
    echo 'Timeout';
    echo $e->getMessage();
} catch (Exception $e) {
    echo 'Something went wrong';
    echo $e->getMessage();
}

$username = $driver->findElement(WebDriverBy::id('username'));
$password = $driver->findElement(WebDriverBy::id('password'));
$loginBtn = $driver->findElement(WebDriverBy::id('loginbtn'));

$username->sendKeys(getenv('ELP_USERNAME'));
$password->sendKeys(getenv('ELP_PASSWORD'));
$loginBtn->click();

try {
    $driver->wait(10, 100)->until(
        WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::tagName('body'))
    );
} catch (NoSuchElementException|TimeoutException|Exception $e) {
    echo $e->getMessage();
}

if ($loginLink == $driver->getCurrentURL()) {
    echo 'Login failed';
} else {
    // login succeed, goto course upload page
    $driver->get($uploadCourseLink);
}

// wait for course upload page loaded
$form = $driver->wait(10, 100)->until(
    WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('mform1'))
);
$openUploadFormBtn = $driver->wait(10, 100)->until(
    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('input[type="button"][name="coursefilechoose"]'))
);

// open upload form-in-form
$openUploadFormBtn->click();

try {
    $driver->wait(3, 100)->until(
        WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('a[class="nav-link"]'))
    );

    // Click on the "Upload a file" link
    $aUploadAFile = $driver->findElements(WebDriverBy::cssSelector('a[class="nav-link"]'))[2];
    $aUploadAFile->click();

    // Put the intended CSV file into the file input
    $fileInput = $driver->wait(3, 100)->until(
        WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('input[type="file"][name="repo_upload_file"]'))
    );

    $fileInput->sendKeys($csvPath);

    // Submit the form-in-form
    $fileInputSubmitBtn = $driver->wait(10, 100)->until(
        WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('button[class="fp-upload-btn btn-primary btn"]'))
    );

    $fileInputSubmitBtn->click();

} catch (NoSuchElementException $e) {
    echo 'Element not found';
    echo $e->getMessage();

} catch (TimeoutException $e) {
    echo 'Timeout';
    echo $e->getMessage();
} catch (Exception $e) {
    echo 'Something went wrong';
    echo $e->getMessage();
}

$previewBtn = $driver->wait(10, 100)->until(
    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('input[type="submit"][name="submitbutton"]'))
);

// Proceed to Preview page
$previewBtn->click();

$uploadCoursesBtn = $driver->wait(10, 100)->until(
    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('input[type="submit"][name="submitbutton"]'))
);

// Upload the courses
$uploadCoursesBtn->click();

// Moodle might take long time to process the CSV file, so we need to wait for the page to load
$continueBtn = $driver->wait(180, 100)->until(
    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath('//button[text()="Continue"]'))
);

// parse result table
$resultTable = $driver->findElement(WebDriverBy::cssSelector('table[summary="Upload courses results"]'));
$resultTableRows = $resultTable->findElements(WebDriverBy::cssSelector('tr'));

for ($i = 1; $i < count($resultTableRows); $i++) {
    $row = $resultTableRows[$i];
    $columns = $row->findElements(WebDriverBy::cssSelector('td'));

//    $row = $columns[0]->getText();
//    $courseStatus = $columns[1]->getText();
    $courseId = (int)$columns[2]->getText();    // moodle course ID
//    $courseShort = $columns[3]->getText();
//    $courseFullName = $columns[4]->getText();
    $courseIdNumber = $columns[5]->getText();
    $createStatusText = $columns[6]->getText();

    if (true === empty($courseId)) {
        // create failed, get status from $createStatus
        $rowCreateResult = ['courseIdNumber' => $courseIdNumber, 'createStatus' => $createStatusText];
        $createResult['failed'][] = $rowCreateResult;
    } else {
        // create succeeded, get moodle course ID from $courseId
        $rowCreateResult = ['courseIdNumber' => $courseIdNumber, 'courseId' => $courseId];
        $createResult['succeeded'][] = $rowCreateResult;
    }

}

var_dump($createResult);

// Logout
$driver->get($logoutLink);
$continueLogoutBtn = $driver->wait(10, 1000)->until(
    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath('//button[text()="Continue"]'))
);
$continueLogoutBtn->click();

// Close browser
$driver->close();
$driver->quit();
