-- MySQL dump 10.13  Distrib 5.7.25, for Win64 (x86_64)
--
-- Host: localhost    Database: kumonph_db
-- ------------------------------------------------------
-- Server version	5.7.25

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `tbl_listoffeedescriptions`
--

DROP TABLE IF EXISTS `tbl_listoffeedescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tbl_listoffeedescriptions` (
  `feedesc_id` int(11) NOT NULL AUTO_INCREMENT,
  `feedesc_name` varchar(100) NOT NULL,
  PRIMARY KEY (`feedesc_id`),
  UNIQUE KEY `uq_feedesc_name` (`feedesc_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tbl_listoffeedescriptions`
--

LOCK TABLES `tbl_listoffeedescriptions` WRITE;
/*!40000 ALTER TABLE `tbl_listoffeedescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `tbl_listoffeedescriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblacademic_years`
--

DROP TABLE IF EXISTS `tblacademic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblacademic_years` (
  `acayearid` int(11) NOT NULL AUTO_INCREMENT,
  `ay` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`acayearid`),
  UNIQUE KEY `uq_tblacademic_years_ay` (`ay`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblacademic_years`
--

LOCK TABLES `tblacademic_years` WRITE;
/*!40000 ALTER TABLE `tblacademic_years` DISABLE KEYS */;
INSERT INTO `tblacademic_years` VALUES (1,'2026','2026-07-06 18:50:19','2026-07-06 18:50:19'),(2,'2027','2026-07-06 18:50:19','2026-07-06 18:50:19'),(3,'2028','2026-07-06 18:50:19','2026-07-06 18:50:19'),(4,'2029','2026-07-06 18:50:19','2026-07-06 18:50:19'),(5,'2030','2026-07-06 18:50:19','2026-07-06 18:50:19'),(6,'2031','2026-07-06 18:50:19','2026-07-06 18:50:19'),(7,'2033','2026-07-06 19:12:28','2026-07-06 19:12:28');
/*!40000 ALTER TABLE `tblacademic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblaccounts`
--

DROP TABLE IF EXISTS `tblaccounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblaccounts` (
  `acc_id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `employee_id` varchar(30) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Administrator','User') NOT NULL DEFAULT 'User',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`acc_id`),
  UNIQUE KEY `uq_tblaccounts_username` (`username`),
  KEY `idx_tblaccounts_employee_id` (`employee_id`),
  KEY `idx_tblaccounts_role` (`role`),
  KEY `idx_tblaccounts_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblaccounts`
--

LOCK TABLES `tblaccounts` WRITE;
/*!40000 ALTER TABLE `tblaccounts` DISABLE KEYS */;
INSERT INTO `tblaccounts` VALUES (1,'System Administrator','ADMIN-001','administrator','$2y$10$Llf3IEpmBiQmoA/x5/ImtuxLeW4MvvrpKyPET5XMpeoLxAaiSWL8O','Administrator',1,'2026-07-07 04:37:17','2026-07-06 17:02:32','2026-07-07 04:37:17'),(2,'Center User','USER-001','user','$2y$10$tdw.QrxZuBFu/obB5XHT4e0FTGewmmyyw28KK9zpY.fOdztsXiu6e','User',1,NULL,'2026-07-06 17:02:32','2026-07-06 17:02:32'),(3,'elbren antonio','123457','elbren','$2y$10$KV5sxHXBDsyVycnC3Ik92ukkfC1IZa.wPoezTY3dJHMLjHCPSrFpy','Administrator',1,'2026-07-19 10:33:56','2026-07-06 18:04:22','2026-07-19 10:33:56');
/*!40000 ALTER TABLE `tblaccounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblconfig`
--

DROP TABLE IF EXISTS `tblconfig`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblconfig` (
  `configid` int(11) NOT NULL AUTO_INCREMENT,
  `systemname` varchar(50) NOT NULL,
  `systemcopyright` varchar(80) NOT NULL,
  `systemversion` varchar(15) NOT NULL,
  PRIMARY KEY (`configid`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblconfig`
--

LOCK TABLES `tblconfig` WRITE;
/*!40000 ALTER TABLE `tblconfig` DISABLE KEYS */;
INSERT INTO `tblconfig` VALUES (1,'Lexora','Lexora 2026','2026.01');
/*!40000 ALTER TABLE `tblconfig` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblfamilies`
--

DROP TABLE IF EXISTS `tblfamilies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblfamilies` (
  `family_id` int(11) NOT NULL AUTO_INCREMENT,
  `family_name` varchar(150) DEFAULT NULL,
  `anchor_stud_auto` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`family_id`),
  UNIQUE KEY `uq_family_name` (`family_name`),
  UNIQUE KEY `uq_family_anchor` (`anchor_stud_auto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblfamilies`
--

LOCK TABLES `tblfamilies` WRITE;
/*!40000 ALTER TABLE `tblfamilies` DISABLE KEYS */;
/*!40000 ALTER TABLE `tblfamilies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblgradelevel`
--

DROP TABLE IF EXISTS `tblgradelevel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblgradelevel` (
  `gradeid` int(11) NOT NULL AUTO_INCREMENT,
  `grade_level_desc` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`gradeid`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblgradelevel`
--

LOCK TABLES `tblgradelevel` WRITE;
/*!40000 ALTER TABLE `tblgradelevel` DISABLE KEYS */;
INSERT INTO `tblgradelevel` VALUES (1,'PK1','2026-07-06 19:47:03','2026-07-06 19:47:03'),(2,'PK2','2026-07-06 19:47:03','2026-07-06 19:47:03'),(3,'PK3','2026-07-06 19:47:03','2026-07-06 19:47:03'),(4,'K','2026-07-06 19:47:03','2026-07-06 19:47:03'),(5,'1','2026-07-06 19:47:03','2026-07-06 19:47:03'),(6,'2','2026-07-06 19:47:03','2026-07-06 19:47:03'),(7,'3','2026-07-06 19:47:03','2026-07-06 19:47:03'),(8,'4','2026-07-06 19:47:03','2026-07-06 19:47:03'),(9,'5','2026-07-06 19:47:03','2026-07-06 19:47:03'),(10,'6','2026-07-06 19:47:03','2026-07-06 19:47:03'),(11,'7','2026-07-06 19:47:03','2026-07-06 19:47:03'),(12,'8','2026-07-06 19:47:03','2026-07-06 19:47:03'),(13,'9','2026-07-06 19:47:03','2026-07-06 19:47:03'),(14,'10','2026-07-06 19:47:03','2026-07-06 19:47:03'),(15,'11','2026-07-06 19:47:03','2026-07-06 19:47:03'),(16,'12','2026-07-06 19:47:03','2026-07-06 19:47:03');
/*!40000 ALTER TABLE `tblgradelevel` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tbllevels`
--

DROP TABLE IF EXISTS `tbllevels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tbllevels` (
  `levelid` int(11) NOT NULL AUTO_INCREMENT,
  `level_code` varchar(20) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`levelid`),
  UNIQUE KEY `uq_tbllevels_level_code` (`level_code`),
  KEY `idx_tbllevels_sort_order` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tbllevels`
--

LOCK TABLES `tbllevels` WRITE;
/*!40000 ALTER TABLE `tbllevels` DISABLE KEYS */;
INSERT INTO `tbllevels` VALUES (1,'6A',10,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(2,'5A',20,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(3,'4A',30,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(4,'3A',40,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(5,'2A',50,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(6,'A',60,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(7,'B',70,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(8,'C',80,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(9,'D',90,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(10,'E',100,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(11,'F',110,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(12,'G',120,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(13,'H',130,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(14,'I',140,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(15,'J',150,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(16,'K',160,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(17,'L',170,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(18,'M',180,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(19,'N',190,'2026-07-07 04:56:44','2026-07-07 04:56:44'),(20,'O',200,'2026-07-07 04:56:44','2026-07-07 04:56:44');
/*!40000 ALTER TABLE `tbllevels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblpayments`
--

DROP TABLE IF EXISTS `tblpayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblpayments` (
  `payid` int(11) NOT NULL AUTO_INCREMENT,
  `paydescription` varchar(100) NOT NULL,
  `pay_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `charge_scope` varchar(20) NOT NULL DEFAULT 'student',
  `pay_ay` int(11) NOT NULL,
  PRIMARY KEY (`payid`),
  UNIQUE KEY `uq_tblpayments_year_description` (`pay_ay`,`paydescription`),
  KEY `idx_tblpayments_year` (`pay_ay`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblpayments`
--

LOCK TABLES `tblpayments` WRITE;
/*!40000 ALTER TABLE `tblpayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `tblpayments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblpayments_history`
--

DROP TABLE IF EXISTS `tblpayments_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblpayments_history` (
  `payhist_id` int(11) NOT NULL AUTO_INCREMENT,
  `studentid` varchar(50) NOT NULL,
  `family_id` int(11) DEFAULT NULL,
  `ayid` int(11) NOT NULL,
  `payid` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `date_paid` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `trans_number` varchar(50) NOT NULL,
  PRIMARY KEY (`payhist_id`),
  KEY `idx_payhist_student_year` (`studentid`,`ayid`),
  KEY `idx_payhist_family_id` (`family_id`),
  KEY `idx_payhist_payid` (`payid`),
  KEY `idx_payhist_trans_number` (`trans_number`),
  KEY `idx_payhist_date_paid` (`date_paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblpayments_history`
--

LOCK TABLES `tblpayments_history` WRITE;
/*!40000 ALTER TABLE `tblpayments_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `tblpayments_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblschedules`
--

DROP TABLE IF EXISTS `tblschedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblschedules` (
  `scheduleid` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`scheduleid`),
  UNIQUE KEY `uq_tblschedules_name` (`schedule_name`),
  KEY `idx_tblschedules_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblschedules`
--

LOCK TABLES `tblschedules` WRITE;
/*!40000 ALTER TABLE `tblschedules` DISABLE KEYS */;
INSERT INTO `tblschedules` VALUES (1,'Monday and Thursday',1,1,'2026-07-07 11:17:03','2026-07-07 11:17:03'),(2,'Tuesday and Friday',2,1,'2026-07-07 11:17:03','2026-07-07 11:17:03'),(3,'Wednesday and Saturday',3,1,'2026-07-07 11:17:03','2026-07-07 11:17:03');
/*!40000 ALTER TABLE `tblschedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblsectioning`
--

DROP TABLE IF EXISTS `tblsectioning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblsectioning` (
  `secid` int(11) NOT NULL AUTO_INCREMENT,
  `gradeid` int(11) DEFAULT NULL,
  `sec_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`secid`),
  UNIQUE KEY `uq_tblsectioning_grade_section` (`gradeid`,`sec_name`),
  KEY `idx_tblsectioning_gradeid` (`gradeid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblsectioning`
--

LOCK TABLES `tblsectioning` WRITE;
/*!40000 ALTER TABLE `tblsectioning` DISABLE KEYS */;
/*!40000 ALTER TABLE `tblsectioning` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblsettings`
--

DROP TABLE IF EXISTS `tblsettings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblsettings` (
  `setid` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`setid`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblsettings`
--

LOCK TABLES `tblsettings` WRITE;
/*!40000 ALTER TABLE `tblsettings` DISABLE KEYS */;
INSERT INTO `tblsettings` VALUES (1,'1');
/*!40000 ALTER TABLE `tblsettings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblstudent_enrollments`
--

DROP TABLE IF EXISTS `tblstudent_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblstudent_enrollments` (
  `enrollment_id` int(11) NOT NULL AUTO_INCREMENT,
  `stud_auto` int(11) NOT NULL,
  `ayid` int(11) NOT NULL,
  `gradeid` int(11) NOT NULL,
  `levelid` int(11) DEFAULT NULL,
  `enroll_date` date DEFAULT NULL,
  `scheduleid` int(11) DEFAULT NULL,
  `secid` int(11) DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `uq_student_enrollment_ay` (`stud_auto`,`ayid`),
  KEY `idx_student_enrollments_ayid` (`ayid`),
  KEY `idx_student_enrollments_gradeid` (`gradeid`),
  KEY `idx_student_enrollments_secid` (`secid`),
  KEY `idx_student_enrollments_status` (`status`),
  KEY `idx_student_enrollments_levelid` (`levelid`),
  KEY `idx_student_enrollments_scheduleid` (`scheduleid`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblstudent_enrollments`
--

LOCK TABLES `tblstudent_enrollments` WRITE;
/*!40000 ALTER TABLE `tblstudent_enrollments` DISABLE KEYS */;
INSERT INTO `tblstudent_enrollments` VALUES (129,1,1,1,1,'2026-07-07',1,NULL,1,'2026-07-07 14:30:12','2026-07-10 19:06:53'),(138,2,1,3,1,'2026-05-11',1,NULL,1,'2026-07-09 07:26:42','2026-07-09 07:26:42');
/*!40000 ALTER TABLE `tblstudent_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblstudent_status`
--

DROP TABLE IF EXISTS `tblstudent_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblstudent_status` (
  `status_id` int(11) NOT NULL AUTO_INCREMENT,
  `status_name` varchar(100) NOT NULL,
  `status_desc` text,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`status_id`),
  UNIQUE KEY `uq_tblstudent_status_name` (`status_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblstudent_status`
--

LOCK TABLES `tblstudent_status` WRITE;
/*!40000 ALTER TABLE `tblstudent_status` DISABLE KEYS */;
INSERT INTO `tblstudent_status` VALUES (1,'Enrolled',NULL,1),(2,'Temporarily Enrolled',NULL,1),(3,'Dropped',NULL,1),(4,'Transferred',NULL,1),(5,'Completed',NULL,1),(6,'Graduated',NULL,1),(7,'With Balance',NULL,1);
/*!40000 ALTER TABLE `tblstudent_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tblstudents`
--

DROP TABLE IF EXISTS `tblstudents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tblstudents` (
  `stud_auto` int(11) NOT NULL AUTO_INCREMENT,
  `studentid` varchar(50) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `middlename` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) NOT NULL,
  `active_mobile_number` varchar(30) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `family_id` int(11) DEFAULT NULL,
  `gradeid` int(11) DEFAULT NULL,
  `levelid` int(11) DEFAULT NULL,
  `secid` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` int(11) NOT NULL DEFAULT '1',
  `ay` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`stud_auto`),
  UNIQUE KEY `uq_tblstudents_studentid` (`studentid`),
  KEY `idx_students_family_id` (`family_id`),
  KEY `idx_students_gradeid` (`gradeid`),
  KEY `idx_students_status` (`status`),
  KEY `idx_students_levelid` (`levelid`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tblstudents`
--

LOCK TABLES `tblstudents` WRITE;
/*!40000 ALTER TABLE `tblstudents` DISABLE KEYS */;
INSERT INTO `tblstudents` VALUES (1,'6082550389921','FRANCINE AMELIE','osorio','JABONERO','09270125388','2021-10-30','IMG_6a4c1eaf387f75.63623321.png',NULL,1,1,NULL,'2026-07-07 05:31:27',1,'1'),(2,'6082650297034','JOSE','JAIME LEANDRO','ROSAL',NULL,'2022-04-26',NULL,NULL,3,1,NULL,'2026-07-08 18:22:17',1,'1'),(3,'6082650297935','KLAY','DARREN','SOLIS',NULL,'2021-07-28',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(4,'6082650212372','FRANCO','ALAB','BARTOLINE',NULL,'2022-11-06',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(5,'6082550675291','KARLUS','JAN NIÑO','BIALA',NULL,'2022-01-24',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(6,'6082650039740','KARLENE','ELIZA','CAGAS',NULL,'2022-11-07',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(7,'6082550553834','AMANDA','FREYJA YLAINE','CALAMOHOY',NULL,'2021-12-07',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(8,'6082550489416','EMITA','JELLIAM','ENCABO',NULL,'2021-02-28',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(9,'6082650055306','ANDRICIA','BLAIRE','ENGI',NULL,'2022-08-26',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(10,'6082550509954','MAX','TIMOTHY','FLORENO',NULL,'2021-11-03',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(11,'6082650210798','SCARLETH','AMANDA','IDEA',NULL,'2021-02-11',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(12,'6082650099904','JHALIX','AILLARD','LIMBRE',NULL,'2020-12-18',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(13,'6082650336801','ELISE','CRYSTAL','LOPEZ',NULL,'2022-11-03',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(14,'6082650013030','ELIJAH','KALIX','MASOCOL',NULL,'2022-03-10',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(15,'6082650007114','SYFR','KROS','OYAO',NULL,'2022-03-16',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(16,'6082650011432','KELSEY','BLAIR','RAMOS',NULL,'2022-11-20',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(17,'6082550390026','JUSTICE','AYDEN','SARAGENA',NULL,'2021-11-29',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(18,'6082650210606','ALIYAH','AMARA','TESIORNA',NULL,'2023-04-30',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(19,'6082650179934','ZIAN','KAEL','TEVES',NULL,'2023-03-03',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(20,'6082650210651','AMAYA','LUCILLA ADIEL','ALDE',NULL,'2021-09-29',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(21,'6082650297485','XIANNAH','ABRIELLE','ALPAS',NULL,'2021-12-25',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(22,'6082550383769','AEVAN','ZACCHAEUS','DEGUILMO',NULL,'2020-09-07',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(23,'6082550385398','MARIA','NATHALIE EREN','DURUIN',NULL,'2021-07-04',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(24,'6082550390439','ADOLF','LUTHER','EMNACE',NULL,'2020-07-23',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(25,'6082550396578','ANEHKA','MARGARUETTE','GERAL',NULL,'2021-10-12',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(26,'6082550473835','RAVI','ISAAC','HO',NULL,'2021-01-08',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(27,'6082650297805','CHARLES','AARON','MACALISANG',NULL,'2022-12-08',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(28,'6082550669665','SERSIE','','MAILWAS',NULL,'2021-04-28',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(29,'6082650297539','RAPHAEL','JOHN','MARISCAL',NULL,'2022-04-29',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(30,'6082550390132','MARCO','MARTIN','MARTEL',NULL,'2021-04-12',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(31,'6082650012446','FRANCE','LEVI','MAYOR',NULL,'2021-05-18',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(32,'6082550599108','KYRIOS','ELION','PAGAS',NULL,'2021-03-31',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(33,'6082650297782','THADDEUS','MIGUEL','PENAS',NULL,'2021-01-22',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(34,'6082550491525','ZION','GABRIEL','RESURRECCION',NULL,'2020-07-17',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(35,'6082650095012','MARIJED','FAITH','SALARDA',NULL,'2021-09-09',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(36,'6082650007503','JOHN','ALLEN','SANCHEZ',NULL,'2021-06-20',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(37,'6082650301731','SKY','SAMELLIE ADELE','SANCHEZ',NULL,'2021-09-21',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(38,'6082550384346','CARLSON','GRAE','TANDUG',NULL,'2021-05-05',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(39,'6082650297454','KESIAH','ABREIM','ABELLA',NULL,'2020-11-27',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(40,'6082650311600','SAMUEL','ELIJAH','ACOJEDO',NULL,'2020-12-23',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(41,'6082650299847','KATHNISS','ANDREA','ALDAMIA',NULL,'2021-03-21',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(42,'6082550390385','GWYNETH','NICOLE','ALESNA',NULL,'2020-03-15',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(43,'6082650297911','ROSÉ','FIOR','BACAOCO',NULL,'2021-01-27',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(44,'6082650044799','HALIYA','HEISABEL','BAGAY',NULL,'2020-07-16',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(45,'6082550605281','SOPHIA','','BLANCO',NULL,'2020-12-11',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(46,'6082550396196','THIRDY','JOSEF REIGN','CAGAS',NULL,'2020-06-17',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(47,'6082650297133','ALEXAVIER','GIANNIS DY','CARPENTERO',NULL,'2020-03-28',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(48,'6082650302929','KEN','JUNO','CONCILIADO',NULL,'2020-03-20',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(49,'6082650179828','ZIAN','NATHANIEL','DASARGO',NULL,'2020-06-10',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(50,'6082550644891','TRAVIS','WYLLRE CRUISE','DAUGDAUG',NULL,'2020-07-04',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(51,'6082650211382','ERN','GLENDON','EBORDA',NULL,'2021-03-19',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(52,'6082650191820','ARIAH','VICTORIA','EVANGELISTA',NULL,'2021-01-10',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(53,'6082550390354','KEZIAH','JUCHELL','GASANG',NULL,'2020-01-26',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,''),(54,'6082650098075','JAN','HENRY','GIGER',NULL,'2020-01-20',NULL,NULL,NULL,NULL,NULL,'2026-07-08 18:22:17',1,'');
/*!40000 ALTER TABLE `tblstudents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tbltransaction_logs`
--

DROP TABLE IF EXISTS `tbltransaction_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tbltransaction_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `ayid` int(11) NOT NULL DEFAULT '0',
  `studentid` varchar(50) DEFAULT NULL,
  `trans_number` varchar(100) DEFAULT NULL,
  `payhist_id` int(11) DEFAULT NULL,
  `payid` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `action_label` varchar(120) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `details` text,
  `performed_by` varchar(150) DEFAULT NULL,
  `performed_role` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_transaction_logs_ayid` (`ayid`),
  KEY `idx_transaction_logs_studentid` (`studentid`),
  KEY `idx_transaction_logs_trans_number` (`trans_number`),
  KEY `idx_transaction_logs_action_type` (`action_type`),
  KEY `idx_transaction_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tbltransaction_logs`
--

LOCK TABLES `tbltransaction_logs` WRITE;
/*!40000 ALTER TABLE `tbltransaction_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `tbltransaction_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'kumonph_db'
--

--
-- Dumping routines for database 'kumonph_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-19 12:46:00
