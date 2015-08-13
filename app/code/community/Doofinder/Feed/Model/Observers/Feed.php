<?php

class Doofinder_Feed_Model_Observers_Feed
{

    private $config;

    private $storeCode;

    private $productCount;


    public function generateFeed($observer)
    {
        $stores = Mage::app()->getStores();
        $helper = Mage::helper('doofinder_feed');


        // Get doofinder proces smodel
        $process = Mage::getModel('doofinder_feed/cron')->load($observer->getScheduleId(), 'schedule_id');
        Mage::log($process->getData());
        if (!$process->getData()) {
            return;
        }

        $scheduleId = $process->getScheduleId();

        // Get store code
        $this->storeCode = $process->getStoreCode();

        // Get store config
        $this->config = $helper->getStoreConfig($this->storeCode);

        if ($this->config['enabled']) {
            try {
                // Clear out the message
                $process->setMessage($helper::MSG_EMPTY);

                // Get data model for store cron
                $dataModel = Mage::getModel('cron/schedule');


                // Get store cron data
                $data = $dataModel->load($scheduleId);

                // Get current offset
                $offset = intval($process->getOffset());

                // Get step size
                $stepSize = intval($this->config['stepSize']);

                // Set paths
                $path = $helper->getFeedPath($this->storeCode);
                $tmpPath = $helper->getFeedTemporaryPath($this->storeCode);

                // Get job code
                $jobCode = $helper::JOB_CODE;

                // Set options for cron generator
                $options = array(
                    '_limit_' => $stepSize,
                    '_offset_' => $offset,
                    'store_code' => $this->config['storeCode'],
                    'grouped' => $this->_getBoolean($this->config['grouped']),
                    'display_price' => $this->_getBoolean($this->config['display_price']),
                    'minimal_price' => $this->_getBoolean('minimal_price', false),
                    'customer_group_id' => 0,
                );

                $generator = Mage::getModel('doofinder_feed/generator', $options);

                $xmlData = $generator->run();

                // If there were errors log them
                if ($errors = $generator->getErrors()) {
                    $process->setErrorStack($process->getErrorStack() + count($errors));

                    foreach ($errors as $error) {
                        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::ERROR, $error);
                    }
                }

                $message = $helper->__('Processed products with ids in range %d - %d', $offset + 1, $generator->getLastProcessedProductId());
                Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $message);

                // If there is new data append to xml.tmp else convert into xml
                if ($xmlData) {
                    $dir = Mage::getBaseDir('media').DS.'doofinder';

                    // If directory doesn't exist create one
                    if (!file_exists($dir)) {
                        $helper->createFeedDirectory($dir);
                    }

                    // If file can not be save throw an error
                    if (!$success = file_put_contents($tmpPath, $xmlData, FILE_APPEND | LOCK_EX)) {
                        Mage::throwException($helper->__("File can not be saved: {$tmpPath}"));
                    }

                    $this->productCount = $generator->getProductCount();
                } else {
                    Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::WARNING, $helper->__('No data added to feed'));
                }

                // Set process offset and progress
                $process->setOffset($generator->getLastProcessedProductId());
                $process->setComplete(sprintf('%0.1f%%', $generator->getProgress() * 100));

                if (!$generator->isFeedDone()) {
                    $this->_createNewSchedule($process);
                } else {
                    Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Feed generation completed'));

                    if (!rename($tmpPath, $path)) {
                        Mage::throwException($helper->__("Cannot rename {$tmpPath} to {$path}"));
                    }

                    $process->setMessage($helper->__('Last process successfully completed. Now waiting for new schedule.'));
                    $this->_endProcess($process);
                }

            } catch (Exception $e) {
                Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::ERROR, $e->getMessage());
                $process->setErrorStack($process->getErrorStack() + 1);
                $process->setMessage($e->getMessage());
                $this->_createNewSchedule($process);
            }
        }
    }

    /**
     * Cast any value to bool
     * @param mixed $value
     * @param bool $defaultValue
     * @return bool
     */
    protected function _getBoolean($value, $defaultValue = false)
    {
        if (is_numeric($value)) {
            if ($value)
                return true;
            else
                return false;
        }

        $yes = array('true', 'on', 'yes');
        $no  = array('false', 'off', 'no');

        if ( in_array($value, $yes) )
            return true;

        if ( in_array($value, $no) )
            return false;

        return $defaultValue;
    }


    /**
     * Converts time string into array.
     * @param string $time
     * @return array
     */
    protected function timeToArray($time = null) {
        // Declare new time
        $newTime;
        // Validate $time variable
        if(!$time || !is_string($time) || substr_count($time, ',') < 2) {
            Mage::throwException('Incorrect time string.');
            return false;
        }

        list($min, $day, $month,) = explode(',', $time);

        $newTime = array(
            'min'   =>  $min,
            'day'   =>  $day,
            'month'  =>  $month,
        );
        return $newTime;
    }

    /**
     * Creates new schedule entry.
     * @param Doofinder_Feed_Model_Cron $process
     */

    private function _createNewSchedule(Doofinder_Feed_Model_Cron $process) {
        $helper = Mage::helper('doofinder_feed');

        // Set new schedule time
        $timezoneOffset = $helper->getTimezoneOffset();
        $delayInMin = intval($this->config['stepDelay']);
        $timecreated   = strftime("%Y-%m-%d %H:%M:%S",  mktime(date("H"), date("i"), date("s"), date("m"), date("d"), date("Y")));
        $localTimescheduled = strftime("%Y-%m-%d %H:%M:%S",  mktime(date("H") + $timezoneOffset, date("i") + $delayInMin, date("s"), date("m"), date("d"), date("Y")));
        $timescheduled = strftime("%Y-%m-%d %H:%M:%S",  mktime(date("H"), date("i") + $delayInMin, date("s"), date("m"), date("d"), date("Y")));

        // Set new schedule in cron_schedule
        $newSchedule = Mage::getModel('cron/schedule');
        $newSchedule->setCreatedAt($timecreated)
            ->setJobCode($helper::JOB_CODE)
            ->setScheduledAt($timescheduled)
            ->save();

        // Prepare new process data
        $schedule_id = $newSchedule->getId();
        $last_schedule_id = $process->getScheduleId();
        $status = $helper::STATUS_RUNNING;
        $nextRun = '-';


        // Set process data and save
        $process->setStatus($status)
            ->setNextRun('-')
            ->setNextIteration($localTimescheduled)
            ->setScheduleId($schedule_id)
            ->save();

        $lastSchedule = Mage::getModel('cron/schedule')->load($last_schedule_id)->delete();

        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Scheduling the next step for %s', $timescheduled));

    }
    /**
     * Concludes process.
     * @param Doofinder_Feed_Model_Cron $process
     */
    private function _endProcess(Doofinder_Feed_Model_Cron $process) {
        $helper = Mage::helper('doofinder_feed');
        // Prepare data
        $data = array(
            'status'    =>  $helper::STATUS_WAITING,
            'next_run' => '-',
            'next_iteration' => '-',
            'last_feed_name' => $this->config['xmlName'],
            'schedule_id' => null,
        );

        $process->addData($data)->save();
    }




}
