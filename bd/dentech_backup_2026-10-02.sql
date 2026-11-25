-- MySQL dump 10.13  Distrib 8.0.19, for Win64 (x86_64)
--
-- Host: localhost    Database: evoluqi
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `agendamentos`
--

DROP TABLE IF EXISTS `agendamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agendamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paciente_id` int(11) DEFAULT NULL,
  `paciente_nome` varchar(255) DEFAULT NULL,
  `procedimento` varchar(255) NOT NULL,
  `data` date NOT NULL,
  `horario` time NOT NULL,
  `status` enum('agendado','confirmado','cancelado','concluido') NOT NULL DEFAULT 'agendado',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `plano_item_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `paciente_id` (`paciente_id`),
  KEY `idx_agendamento_plano_item` (`plano_item_id`),
  CONSTRAINT `agendamentos_ibfk_1` FOREIGN KEY (`paciente_id`) REFERENCES `prontuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agendamento_plano_item` FOREIGN KEY (`plano_item_id`) REFERENCES `planos_tratamento_itens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agendamentos`
--

LOCK TABLES `agendamentos` WRITE;
/*!40000 ALTER TABLE `agendamentos` DISABLE KEYS */;
INSERT INTO `agendamentos` VALUES (3,NULL,NULL,'teste','2026-08-19','09:00:00','agendado','2026-08-18 13:34:39',NULL),(4,NULL,'teste','teste','2026-08-19','20:23:00','agendado','2026-08-19 13:23:15',NULL),(5,4,NULL,'teste','2026-08-21','12:00:00','confirmado','2026-08-21 14:21:21',NULL),(6,4,'Paula','Consulta','2026-08-27','15:00:00','concluido','2026-08-26 16:37:09',NULL),(13,4,'Paula','Restauração','2026-08-26','15:30:00','concluido','2026-08-26 20:20:21',7),(14,4,'Paula','Limpeza dental','2026-08-26','19:00:00','concluido','2026-08-26 21:10:32',8),(15,5,'Gustavo','Consulta','2026-08-26','20:00:00','concluido','2026-08-26 21:51:22',NULL),(16,5,'Gustavo','teste','2026-09-01','23:30:00','concluido','2026-09-02 02:15:13',NULL),(17,5,'Gustavo','teste 02','2026-09-02','11:00:00','concluido','2026-09-02 13:07:40',NULL),(18,5,'Gustavo','teste 03','2026-09-02','11:40:00','agendado','2026-09-02 13:26:33',NULL),(19,5,'Gustavo','Teste 04','2026-09-02','11:50:00','concluido','2026-09-02 13:26:58',NULL),(20,5,'Gustavo','Teste 05','2026-09-02','12:00:00','agendado','2026-09-02 14:22:54',NULL),(21,5,'Gustavo','Teste 06','2026-09-02','12:10:00','agendado','2026-09-02 14:23:11',NULL),(22,5,'Gustavo','Teste 07','2026-09-02','12:30:00','agendado','2026-09-02 14:23:34',NULL),(23,5,'Gustavo','Teste 08','2026-09-02','12:45:00','agendado','2026-09-02 14:23:57',NULL),(24,5,'Gustavo','Teste Agendamento Procedimento','2026-09-09','02:17:00','agendado','2026-09-09 15:17:33',NULL),(25,5,'Gustavo','Limpeza dental','2026-10-01','18:06:00','agendado','2026-10-01 21:06:23',NULL);
/*!40000 ALTER TABLE `agendamentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `consentimentos`
--

DROP TABLE IF EXISTS `consentimentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consentimentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prontuario_id` int(11) NOT NULL,
  `aceito` tinyint(1) NOT NULL DEFAULT 0,
  `data_aceite` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_consentimento_prontuario` (`prontuario_id`),
  CONSTRAINT `fk_consentimento_prontuario` FOREIGN KEY (`prontuario_id`) REFERENCES `prontuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `consentimentos`
--

LOCK TABLES `consentimentos` WRITE;
/*!40000 ALTER TABLE `consentimentos` DISABLE KEYS */;
/*!40000 ALTER TABLE `consentimentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `estoque`
--

DROP TABLE IF EXISTS `estoque`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `quantidade` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unidade` varchar(50) NOT NULL DEFAULT 'unidade',
  `estoque_minimo` decimal(10,2) NOT NULL DEFAULT 5.00,
  `valor_item` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_sugerido` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `estoque`
--

LOCK TABLES `estoque` WRITE;
/*!40000 ALTER TABLE `estoque` DISABLE KEYS */;
INSERT INTO `estoque` VALUES (1,'Luva descartável',1.00,'pacote',2.00,15.00,20.00,'2026-09-14 12:30:53'),(2,'Algodão',40.00,'unidade',15.00,1.50,3.00,'2026-09-09 15:01:26'),(3,'Resina Composta Z350 XT A2 4g',15.00,'unidade',5.00,239.90,310.00,'2026-09-09 15:34:48'),(4,'Ácido Fosfórico 37% Condac Gel',7.00,'unidade',3.00,25.50,35.00,'2026-09-24 00:53:12'),(5,'Adesivo Ambar FGM 6ml',5.00,'unidade',2.00,63.00,85.00,'2026-09-09 15:34:48'),(6,'Anestésico Mepeval 2% com Epinefrina',3.00,'caixa',1.00,190.00,250.00,'2026-09-09 15:34:48'),(7,'Luva de Procedimento Nitrílica M (100un)',12.00,'caixa',4.00,45.00,60.00,'2026-09-09 15:34:48'),(8,'Babador Descartável Impermeável (100un)',6.00,'pacote',2.00,32.00,45.00,'2026-09-09 15:34:48'),(9,'Broca Diamantada Esférica 1014 (5un)',4.00,'blister',2.00,28.90,40.00,'2026-09-09 15:34:48'),(10,'Agulha Gengival Curta 30G (100un)',2.00,'caixa',1.00,65.00,85.00,'2026-09-09 15:34:48'),(11,'Silicone de Adição Perfit Kit',3.00,'kit',1.00,280.00,360.00,'2026-09-09 15:34:48'),(12,'Ionômero de Vidro Maxxion R A3',7.00,'unidade',3.00,78.00,105.00,'2026-09-09 15:34:48');
/*!40000 ALTER TABLE `estoque` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lancamentos_financeiros`
--

DROP TABLE IF EXISTS `lancamentos_financeiros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lancamentos_financeiros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('receita','despesa') NOT NULL,
  `categoria` varchar(100) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `data` date NOT NULL,
  `forma_pagamento` varchar(50) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `parcelas` int(11) NOT NULL DEFAULT 1,
  `status` enum('pago','pendente') NOT NULL DEFAULT 'pendente',
  `data_pagamento` date DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `orcamento_id` int(11) DEFAULT NULL,
  `parcela_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `procedimento_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lancamento_parcela` (`parcela_id`),
  KEY `idx_data` (`data`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_status` (`status`),
  KEY `idx_orcamento` (`orcamento_id`),
  KEY `idx_lancamento_procedimento` (`procedimento_id`),
  CONSTRAINT `fk_lancamento_procedimento` FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lancamentos_financeiros`
--

LOCK TABLES `lancamentos_financeiros` WRITE;
/*!40000 ALTER TABLE `lancamentos_financeiros` DISABLE KEYS */;
INSERT INTO `lancamentos_financeiros` VALUES (1,'receita','Orçamento','Orçamento #6 - Parcela 1/4','2026-08-19','A definir',128.00,4,'pago','2026-08-19','Receita gerada pelo orçamento #6. Parcela 1/4.',6,10,'2026-08-19 04:12:04',NULL),(2,'receita','Orçamento','Orçamento #6 - Parcela 2/4','2026-10-19','A definir',128.00,4,'pendente',NULL,'Receita gerada pelo orçamento #6. Parcela 2/4.',6,11,'2026-08-19 04:12:04',NULL),(3,'receita','Orçamento','Orçamento #6 - Parcela 3/4','2026-11-19','A definir',128.00,4,'pendente',NULL,'Receita gerada pelo orçamento #6. Parcela 3/4.',6,12,'2026-08-19 04:12:04',NULL),(4,'receita','Orçamento','Orçamento #6 - Parcela 4/4','2026-12-19','A definir',128.00,4,'pendente',NULL,'Receita gerada pelo orçamento #6. Parcela 4/4.',6,13,'2026-08-19 04:12:04',NULL),(5,'receita','Orçamento','Orçamento #7 - Parcela 1/3','2026-09-19','A definir',50.00,3,'pendente',NULL,'Receita gerada pelo orçamento #7. Parcela 1/3.',7,14,'2026-08-19 04:24:42',NULL),(6,'receita','Orçamento','Orçamento #7 - Parcela 2/3','2026-10-19','A definir',50.00,3,'pendente',NULL,'Receita gerada pelo orçamento #7. Parcela 2/3.',7,15,'2026-08-19 04:24:42',NULL),(7,'receita','Orçamento','Orçamento #7 - Parcela 3/3','2026-11-19','A definir',50.00,3,'pendente',NULL,'Receita gerada pelo orçamento #7. Parcela 3/3.',7,16,'2026-08-19 04:24:42',NULL),(8,'receita','Orçamento odontológico','Orçamento #9 - Paula - Parcela 1','2026-09-23','PIX',100.00,1,'pendente',NULL,'Receita gerada pelo orçamento #9. Parcela 1/3.',9,20,'2026-08-22 01:43:09',NULL),(9,'receita','Orçamento','Orçamento #9 - Parcela 2/3','2026-10-22','A definir',100.00,3,'pendente',NULL,'Receita gerada pelo orçamento #9. Parcela 2/3.',9,21,'2026-08-22 01:43:09',NULL),(10,'receita','Orçamento','Orçamento #9 - Parcela 3/3','2026-11-22','A definir',100.00,3,'pendente',NULL,'Receita gerada pelo orçamento #9. Parcela 3/3.',9,22,'2026-08-22 01:43:10',NULL),(11,'receita','Orçamento odontológico','Orçamento #10 - Parcela 1','2026-09-01','PIX',100.00,1,'pago','2026-09-01','Receita gerada pelo orçamento #10. Parcela 1/3.',10,23,'2026-08-22 02:08:51',NULL),(12,'receita','Orçamento','Orçamento #10 - Parcela 2/3','2026-10-22','A definir',100.00,3,'pendente',NULL,'Receita gerada pelo orçamento #10. Parcela 2/3.',10,24,'2026-08-22 02:08:51',NULL),(13,'receita','Orçamento','Orçamento #10 - Parcela 3/3','2026-11-22','A definir',100.00,3,'pendente',NULL,'Receita gerada pelo orçamento #10. Parcela 3/3.',10,25,'2026-08-22 02:08:51',NULL),(15,'receita','Ajuste de procedimento','Desconto do procedimento #3 - Restauração','2026-08-24','A definir',-50.00,1,'pago','2026-08-24','Desconto gerado pela diferença entre o orçamento #10 (R$ 300,00) e o valor realizado do procedimento (R$ 250,00).',10,NULL,'2026-08-24 12:13:21',3),(16,'receita','Procedimento','Procedimento #10 - Consulta - Parcela 1/3','2026-08-31','PIX',33.34,3,'pago','2026-08-31','Cobrança do procedimento #10. Parcela 1/3.',NULL,34,'2026-08-31 13:42:05',10),(17,'receita','Procedimento','Procedimento #10 - Consulta - Parcela 2/3','2026-11-01','PIX',33.33,3,'pendente',NULL,'Cobrança do procedimento #10. Parcela 2/3.',NULL,35,'2026-08-31 13:42:05',10),(18,'receita','Procedimento','Procedimento #10 - Consulta - Parcela 3/3','2026-12-01','PIX',33.33,3,'pendente',NULL,'Cobrança do procedimento #10. Parcela 3/3.',NULL,36,'2026-08-31 13:42:06',10),(19,'receita','Procedimento','Procedimento #11 - Parcela 1','2026-09-01','PIX',100.00,1,'pago','2026-09-01','Cobrança do procedimento #11. Parcela 1/3.',NULL,37,'2026-09-01 14:27:06',11),(20,'receita','Procedimento','Procedimento #11 - Parcela 2','2026-10-01','Cartão de débito',100.00,1,'pago','2026-09-09','Cobrança do procedimento #11. Parcela 2/3.',NULL,38,'2026-09-01 14:27:06',11),(21,'receita','Procedimento','Procedimento #11 - Clareamento - Parcela 3/3','2026-11-01','PIX',100.00,3,'pendente',NULL,'Cobrança do procedimento #11. Parcela 3/3.',NULL,39,'2026-09-01 14:27:07',11),(22,'receita','Orçamento','Orçamento #15 - Parcela 1/1','2026-09-26','A definir',600.00,1,'pendente',NULL,'Receita gerada pelo orçamento #15. Parcela 1/1.',15,30,'2026-09-01 15:57:12',NULL),(23,'receita','Procedimento','Procedimento #12 - Parcela 1','2026-09-02','PIX',225.00,1,'pago','2026-09-02','Cobrança do procedimento #12. Parcela 1/2.',NULL,41,'2026-09-02 02:07:17',12),(24,'receita','Procedimento','Procedimento #12 - Teste - Parcela 2/2','2026-11-02','PIX',225.00,2,'pendente',NULL,'Cobrança do procedimento #12. Parcela 2/2.',NULL,42,'2026-09-02 02:07:17',12),(25,'receita','Procedimento','Procedimento #17 - Parcela 1','2026-09-03','PIX',100.00,1,'pago','2026-09-03','Cobrança do procedimento #17. Parcela 1/1.',NULL,44,'2026-09-03 15:26:24',17),(26,'receita','Consulta Teste','Teste 01','2026-09-04','PIX',500.00,1,'pago','2026-09-04',NULL,NULL,NULL,'2026-09-04 15:25:41',NULL),(27,'despesa','Despesa teste','Teste 03','2026-09-04','Boleto',700.00,1,'pago','2026-09-04',NULL,NULL,NULL,'2026-09-04 15:26:24',NULL),(28,'despesa','Teste 03','Teste 03 pagamento','2026-09-01','PIX',100.00,1,'pago','2026-09-08',NULL,NULL,NULL,'2026-09-06 23:45:53',NULL),(29,'receita','teste 04','Teste 04','2026-09-01','PIX',200.00,1,'pago','2026-09-08',NULL,NULL,NULL,'2026-09-08 14:08:17',NULL),(30,'receita','Procedimento','Procedimento #3 - Parcela 1','2026-09-08','PIX',83.34,1,'pago',NULL,NULL,NULL,31,'2026-09-08 14:32:28',3),(31,'receita','Procedimento','Procedimento #15 - Parcela 1','2026-09-08','Cartão de crédito',66.67,1,'pago',NULL,'Cobrança do procedimento #15. Parcela 1/3.',NULL,50,'2026-09-08 15:31:27',15),(32,'receita','Procedimento','Procedimento #15 - Gustavo - Parcela 2','2026-09-11','PIX',66.67,1,'pendente',NULL,'Cobrança do procedimento #15. Parcela 2/3.',NULL,51,'2026-09-08 15:31:27',15),(33,'receita','Procedimento','Procedimento #15 - teste 02 - Parcela 3/3','2026-11-08','Cartão de crédito',66.66,3,'pendente',NULL,'Cobrança do procedimento #15. Parcela 3/3.',NULL,52,'2026-09-08 15:31:28',15),(34,'receita','Teste','Teste Receita Pago','2026-09-08','PIX',100.00,1,'pago','2026-09-08',NULL,NULL,NULL,'2026-09-08 15:33:53',NULL),(35,'receita','Teste','Teste Receita Pendente','2026-09-06','PIX',100.00,1,'pago','2026-09-09',NULL,NULL,NULL,'2026-09-08 15:34:33',NULL),(36,'receita','Procedimento','Procedimento #16 - Teste 04 - Parcela 1/3','2026-10-09','PIX',133.34,3,'pendente',NULL,'Cobrança do procedimento #16. Parcela 1/3.',NULL,53,'2026-09-09 14:30:22',16),(37,'receita','Procedimento','Procedimento #16 - Parcela 2','2026-11-09','Cartão de crédito',133.33,1,'pago','2026-09-09','Cobrança do procedimento #16. Parcela 2/3.',NULL,54,'2026-09-09 14:30:22',16),(38,'receita','Procedimento','Procedimento #16 - Teste 04 - Parcela 3/3','2026-12-09','PIX',133.33,3,'pendente',NULL,'Cobrança do procedimento #16. Parcela 3/3.',NULL,55,'2026-09-09 14:30:22',16),(39,'despesa','teste','Teste despesa','2026-09-09','PIX',100.00,1,'pago','2026-09-09',NULL,NULL,NULL,'2026-09-09 15:06:44',NULL),(40,'receita','Teste A vista','Teste A vista','2026-09-10','PIX',100.00,1,'pago','2026-09-10',NULL,NULL,NULL,'2026-09-10 14:51:57',NULL),(41,'receita','Teste Parcelado','Teste Parcelado','2026-09-10','PIX',1000.00,3,'pendente',NULL,NULL,NULL,NULL,'2026-09-10 14:53:09',NULL),(42,'receita','Teste novamente','Teste novamente','2026-09-10','PIX',120.91,2,'pendente',NULL,NULL,NULL,NULL,'2026-09-10 14:54:11',NULL),(43,'receita','Teste Parcelado 2x','Teste Parcelado 2x','2026-09-12','Cartão de crédito',150.00,2,'pago','2026-09-12','Teste',NULL,NULL,'2026-09-12 19:48:41',NULL),(44,'despesa','Teste Despesa Parcelado Mod','Teste Despesa Parcelado Mod','2026-09-12','Cartão de débito',250.00,4,'pago','2026-09-12',NULL,NULL,NULL,'2026-09-12 21:40:36',NULL),(45,'receita','Teste Novo Sem Parcelamento','Teste Novo Sem Parcelamento','2026-09-13','Cartão de débito',1200.00,1,'pendente',NULL,NULL,NULL,NULL,'2026-09-13 01:14:41',NULL),(46,'despesa','Teste Energia','Teste Energia','2026-09-13','PIX',100.00,1,'pendente',NULL,NULL,NULL,NULL,'2026-09-14 12:14:48',NULL),(47,'receita','Procedimento','Procedimento #14 - Parcela 1','2026-09-13','PIX',250.00,1,'pago','2026-09-14','Cobrança do procedimento #14. Parcela 1/4.',NULL,58,'2026-09-14 12:31:29',14),(48,'receita','Procedimento','Procedimento #14 - teste - Parcela 2/4','2026-11-14','PIX',250.00,4,'pendente',NULL,'Cobrança do procedimento #14. Parcela 2/4.',NULL,59,'2026-09-14 12:31:29',14),(49,'receita','Procedimento','Procedimento #14 - teste - Parcela 3/4','2026-12-14','PIX',250.00,4,'pendente',NULL,'Cobrança do procedimento #14. Parcela 3/4.',NULL,60,'2026-09-14 12:31:29',14),(50,'receita','Procedimento','Procedimento #14 - teste - Parcela 4/4','2027-01-14','PIX',250.00,4,'pendente',NULL,'Cobrança do procedimento #14. Parcela 4/4.',NULL,61,'2026-09-14 12:31:29',14),(51,'receita','Procedimento','Procedimento #19 - Teste06 - Parcela 1/2','2026-10-24','PIX',50.00,2,'pendente',NULL,'Cobrança do procedimento #19. Parcela 1/2.',NULL,69,'2026-09-24 00:53:41',19),(52,'receita','Procedimento','Procedimento #19 - Teste06 - Parcela 2/2','2026-11-24','PIX',50.00,2,'pendente',NULL,'Cobrança do procedimento #19. Parcela 2/2.',NULL,70,'2026-09-24 00:53:41',19);
/*!40000 ALTER TABLE `lancamentos_financeiros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lancamentos_parcelas`
--

DROP TABLE IF EXISTS `lancamentos_parcelas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lancamentos_parcelas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lancamento_id` int(11) NOT NULL,
  `numero_parcela` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `vencimento` date NOT NULL,
  `status` enum('pendente','paga','atrasada') NOT NULL DEFAULT 'pendente',
  `data_pagamento` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lancamentos_parcelas_lancamento` (`lancamento_id`),
  KEY `idx_lancamentos_parcelas_vencimento` (`vencimento`),
  KEY `idx_lancamentos_parcelas_status` (`status`),
  CONSTRAINT `fk_lancamentos_parcelas_lancamento` FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos_financeiros` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lancamentos_parcelas`
--

LOCK TABLES `lancamentos_parcelas` WRITE;
/*!40000 ALTER TABLE `lancamentos_parcelas` DISABLE KEYS */;
INSERT INTO `lancamentos_parcelas` VALUES (1,41,1,333.34,'2026-09-10','paga','2026-09-12','2026-09-10 14:53:09'),(2,41,2,333.33,'2026-10-10','pendente',NULL,'2026-09-10 14:53:10'),(3,41,3,333.33,'2026-11-10','pendente',NULL,'2026-09-10 14:53:10'),(4,42,1,60.46,'2026-09-10','pendente',NULL,'2026-09-10 14:54:11'),(5,42,2,60.45,'2026-10-10','pendente',NULL,'2026-09-10 14:54:11'),(6,43,1,75.00,'2026-09-12','paga','2026-09-12','2026-09-12 19:48:41'),(7,43,2,75.00,'2026-10-12','paga','2026-09-12','2026-09-12 19:48:41'),(8,44,1,62.50,'2026-09-12','paga','2026-09-12','2026-09-12 21:40:36'),(9,44,2,62.50,'2026-10-12','paga','2026-09-12','2026-09-12 21:40:36'),(10,44,3,62.50,'2026-11-12','paga','2026-09-12','2026-09-12 21:40:36'),(11,44,4,62.50,'2026-12-12','paga','2026-09-12','2026-09-12 21:40:36');
/*!40000 ALTER TABLE `lancamentos_parcelas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(100) DEFAULT 'Sistema',
  `acao` varchar(50) NOT NULL,
  `tabela` varchar(50) DEFAULT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `detalhes` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logs`
--

LOCK TABLES `logs` WRITE;
/*!40000 ALTER TABLE `logs` DISABLE KEYS */;
INSERT INTO `logs` VALUES (1,'Usuario','Criou prontuário','prontuarios',1,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-08-17 13:14:03'),(2,'Usuario','Criou prontuário','prontuarios',2,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-08-17 14:01:47'),(3,'Usuario','Criou prontuário','prontuarios',3,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-08-17 14:37:58'),(4,'Usuario','Criou agendamento','agendamentos',1,'Novo agendamento criado','127.0.0.1','2026-08-18 02:12:08'),(5,'Usuario','Excluiu agendamento','agendamentos',1,'Agendamento removido','127.0.0.1','2026-08-18 02:25:55'),(6,'Usuario','Criou agendamento','agendamentos',2,'Novo agendamento criado','127.0.0.1','2026-08-18 02:26:46'),(7,'Usuario','Recusou orçamento','orcamentos',1,'Orçamento recusado','127.0.0.1','2026-08-18 11:52:39'),(8,'Usuario','Aceitou orçamento','orcamentos',2,'Orçamento aprovado e processo de agendamento iniciado','127.0.0.1','2026-08-18 13:34:39'),(9,'Usuario','Recusou orçamento','orcamentos',3,'Orçamento recusado','127.0.0.1','2026-08-18 15:46:05'),(10,'Usuario','Recusou orçamento','orcamentos',4,'Orçamento recusado','127.0.0.1','2026-08-19 04:11:02'),(11,'Usuario','Criou agendamento','agendamentos',4,'Novo agendamento criado','127.0.0.1','2026-08-19 13:23:15'),(12,'Usuario','Criou prontuário','prontuarios',4,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-08-21 11:36:17'),(13,'Usuario','Criou agendamento','agendamentos',5,'Novo agendamento criado','127.0.0.1','2026-08-21 14:21:22'),(14,'Usuario','Recusou orçamento','orcamentos',11,'Orçamento recusado','127.0.0.1','2026-08-25 13:10:17'),(15,'Usuario','Recusou orçamento','orcamentos',12,'Orçamento recusado','127.0.0.1','2026-08-25 13:10:28'),(16,'Usuario','Criou prontuário','prontuarios',5,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-08-26 16:41:05'),(17,'Usuario','Excluiu agendamento','agendamentos',8,'Agendamento removido','127.0.0.1','2026-08-26 16:46:31'),(18,'Usuario','Excluiu agendamento','agendamentos',11,'Agendamento removido','127.0.0.1','2026-08-26 19:25:26'),(19,'Usuario','Excluiu agendamento','agendamentos',9,'Agendamento removido','127.0.0.1','2026-08-26 20:12:42'),(20,'Usuario','Excluiu agendamento','agendamentos',12,'Agendamento removido','127.0.0.1','2026-08-26 20:12:47'),(21,'Usuario','Excluiu agendamento','agendamentos',7,'Agendamento removido','127.0.0.1','2026-08-26 20:12:54'),(22,'Usuario','Excluiu agendamento','agendamentos',10,'Agendamento removido','127.0.0.1','2026-08-26 20:12:59'),(23,'Usuario','Confirmou orçamento','orcamentos',16,'Orçamento confirmado. Nenhum lançamento financeiro foi criado.','127.0.0.1','2026-09-02 02:04:49'),(24,'Usuario','Confirmou orçamento','orcamentos',17,'Orçamento confirmado. Nenhum lançamento financeiro foi criado.','127.0.0.1','2026-09-03 15:25:02'),(25,'Usuario','Criou prontuário','prontuarios',6,'Novo paciente cadastrado no prontuário','127.0.0.1','2026-09-04 14:21:31'),(26,'Usuario','Confirmou orçamento','orcamentos',18,'Orçamento confirmado. Nenhum lançamento financeiro foi criado.','127.0.0.1','2026-09-08 15:29:41'),(27,'Usuario','Recusou orçamento','orcamentos',19,'Orçamento recusado','127.0.0.1','2026-09-09 14:37:06'),(28,'Usuario','Confirmou orçamento','orcamentos',20,'Orçamento confirmado. Nenhum lançamento financeiro foi criado.','127.0.0.1','2026-09-09 14:37:39'),(29,'Usuario','Confirmou orçamento','orcamentos',21,'Orçamento confirmado. Nenhum lançamento financeiro foi criado.','::1','2026-09-17 15:10:18'),(30,'Usuario','Recusou orçamento','orcamentos',22,'Itens pendentes do orçamento foram recusados. Nenhum lançamento financeiro foi criado.','::1','2026-09-17 15:14:38'),(31,'Usuario','Aceitou item do orçamento','orcamentos_itens',13,'Item do orçamento aceito.','::1','2026-09-18 14:25:56'),(32,'Usuario','Recusou item do orçamento','orcamentos_itens',14,'Item do orçamento recusado.','::1','2026-09-18 14:29:42'),(33,'Usuario','Excluiu item de orçamento','orcamentos_itens',33,'Item \"Aplicação de Selante\" excluído do orçamento #23','::1','2026-09-18 15:03:03'),(34,'Usuario','Excluiu item de orçamento','orcamentos_itens',35,'Item \"Teste 02\" excluído do orçamento #23','::1','2026-09-19 20:47:19'),(35,'Usuario','Excluiu item de orçamento','orcamentos_itens',36,'Item \"Teste 02\" excluído do orçamento #23','::1','2026-09-19 22:01:46'),(36,'Usuario','Recusou item do orçamento','orcamentos_itens',37,'Item do orçamento recusado.','::1','2026-09-21 13:47:26'),(37,'Usuario','Criou prontuário','prontuarios',7,'Novo paciente cadastrado no prontuário','::1','2026-09-22 14:26:14'),(38,'Usuario','Criou prontuário','prontuarios',8,'Novo paciente cadastrado no prontuário e odontograma inicializado','::1','2026-09-25 14:39:14');
/*!40000 ALTER TABLE `logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `odontograma_dentes`
--

DROP TABLE IF EXISTS `odontograma_dentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `odontograma_dentes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prontuario_id` int(11) NOT NULL,
  `numero_dente` varchar(2) NOT NULL,
  `denticao` varchar(20) NOT NULL,
  `estado_geral` varchar(50) NOT NULL DEFAULT 'nao_avaliado',
  `implante_status` varchar(30) NOT NULL DEFAULT 'nao_se_aplica',
  `observacoes` text DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_odontograma_dentes_prontuario` (`prontuario_id`),
  CONSTRAINT `fk_odontograma_dentes_prontuario` FOREIGN KEY (`prontuario_id`) REFERENCES `prontuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `odontograma_dentes`
--

LOCK TABLES `odontograma_dentes` WRITE;
/*!40000 ALTER TABLE `odontograma_dentes` DISABLE KEYS */;
INSERT INTO `odontograma_dentes` VALUES (1,8,'11','permanente','higido','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-10-02 12:23:48'),(2,8,'12','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(3,8,'13','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(4,8,'14','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(5,8,'15','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(6,8,'16','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(7,8,'17','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(8,8,'18','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(9,8,'21','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(10,8,'22','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(11,8,'23','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(12,8,'24','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(13,8,'25','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(14,8,'26','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(15,8,'27','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(16,8,'28','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(17,8,'31','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(18,8,'32','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(19,8,'33','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(20,8,'34','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(21,8,'35','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(22,8,'36','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(23,8,'37','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(24,8,'38','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(25,8,'41','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(26,8,'42','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(27,8,'43','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(28,8,'44','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(29,8,'45','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(30,8,'46','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(31,8,'47','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(32,8,'48','permanente','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(33,8,'51','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(34,8,'52','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(35,8,'53','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(36,8,'54','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(37,8,'55','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(38,8,'61','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(39,8,'62','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(40,8,'63','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(41,8,'64','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(42,8,'65','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(43,8,'71','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(44,8,'72','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(45,8,'73','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(46,8,'74','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(47,8,'75','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(48,8,'81','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(49,8,'82','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(50,8,'83','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(51,8,'84','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14'),(52,8,'85','decidua','nao_avaliado','nao_se_aplica',NULL,'2026-09-25 11:39:14','2026-09-25 11:39:14');
/*!40000 ALTER TABLE `odontograma_dentes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `odontograma_registros`
--

DROP TABLE IF EXISTS `odontograma_registros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `odontograma_registros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `odontograma_dente_id` int(11) NOT NULL,
  `superficie` varchar(20) NOT NULL DEFAULT 'geral',
  `condicao` varchar(50) NOT NULL,
  `observacao` text DEFAULT NULL,
  `procedimento_id` int(11) DEFAULT NULL,
  `plano_tratamento_item_id` int(11) DEFAULT NULL,
  `data_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `arquivado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_odontograma_registros_dente` (`odontograma_dente_id`),
  KEY `idx_odontograma_registros_procedimento` (`procedimento_id`),
  KEY `idx_odontograma_registros_plano` (`plano_tratamento_item_id`),
  CONSTRAINT `fk_odontograma_registros_dente` FOREIGN KEY (`odontograma_dente_id`) REFERENCES `odontograma_dentes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_odontograma_registros_plano` FOREIGN KEY (`plano_tratamento_item_id`) REFERENCES `planos_tratamento_itens` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_odontograma_registros_procedimento` FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `odontograma_registros`
--

LOCK TABLES `odontograma_registros` WRITE;
/*!40000 ALTER TABLE `odontograma_registros` DISABLE KEYS */;
INSERT INTO `odontograma_registros` VALUES (1,1,'vestibular','higido','Teste',NULL,NULL,'2026-09-28 09:33:00','2026-09-28 09:33:00',1),(2,1,'mesial','higido','Teste',NULL,NULL,'2026-09-28 09:33:00','2026-09-28 09:33:00',1),(3,1,'oclusal','higido','Teste',NULL,NULL,'2026-09-28 09:33:00','2026-09-28 09:33:00',1),(4,1,'lingual_palatina','higido','Teste',NULL,NULL,'2026-09-28 09:33:00','2026-09-28 09:33:00',1),(5,1,'geral','higido','Teste 01',NULL,NULL,'2026-09-29 10:47:40','2026-09-29 10:47:40',0),(6,1,'mesial','higido',NULL,NULL,NULL,'2026-09-29 10:48:12','2026-09-29 10:48:12',1),(7,1,'mesial','carie','Teste Mesial',NULL,NULL,'2026-09-29 10:50:50','2026-09-29 10:50:50',1),(8,1,'vestibular','carie',NULL,NULL,NULL,'2026-09-30 10:04:12','2026-09-30 10:04:12',1),(9,1,'vestibular','restaurado',NULL,NULL,NULL,'2026-09-30 10:05:43','2026-09-30 10:05:43',1),(10,1,'vestibular','carie',NULL,NULL,NULL,'2026-09-30 10:23:47','2026-09-30 10:23:47',1),(11,1,'mesial','higido',NULL,NULL,NULL,'2026-09-30 11:10:58','2026-09-30 11:10:58',1),(12,1,'vestibular','carie',NULL,NULL,NULL,'2026-09-30 11:17:26','2026-09-30 11:17:26',1),(13,1,'vestibular','carie',NULL,NULL,NULL,'2026-09-30 12:25:51','2026-09-30 12:25:51',1),(14,1,'mesial','carie',NULL,NULL,NULL,'2026-09-30 12:26:06','2026-09-30 12:26:06',1),(15,1,'vestibular','restaurado',NULL,NULL,NULL,'2026-09-30 12:27:05','2026-09-30 12:27:05',1),(16,1,'vestibular','carie',NULL,NULL,NULL,'2026-09-30 12:51:02','2026-09-30 12:51:02',1),(17,1,'vestibular','restaurado',NULL,NULL,NULL,'2026-09-30 12:51:13','2026-09-30 12:51:13',1),(18,1,'vestibular','fraturado',NULL,NULL,NULL,'2026-10-02 11:06:55','2026-10-02 11:06:55',1),(19,1,'vestibular','restaurado',NULL,NULL,NULL,'2026-10-02 11:07:08','2026-10-02 11:07:08',0),(20,1,'mesial','restaurado',NULL,NULL,NULL,'2026-10-02 11:07:08','2026-10-02 11:07:08',0),(21,1,'oclusal','higido',NULL,NULL,NULL,'2026-10-02 12:23:48','2026-10-02 12:23:48',0),(22,1,'distal','higido',NULL,NULL,NULL,'2026-10-02 12:23:48','2026-10-02 12:23:48',0),(23,1,'lingual_palatina','higido',NULL,NULL,NULL,'2026-10-02 12:23:48','2026-10-02 12:23:48',0);
/*!40000 ALTER TABLE `odontograma_registros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orcamentos`
--

DROP TABLE IF EXISTS `orcamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orcamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paciente_id` int(11) NOT NULL,
  `data_criacao` date NOT NULL,
  `validade` date NOT NULL,
  `status` enum('pendente','parcialmente_aceito','aceito','parcialmente_recusado','recusado') NOT NULL DEFAULT 'pendente',
  `arquivado` tinyint(1) NOT NULL DEFAULT 0,
  `arquivado_em` datetime DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `agendamento_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `paciente_id` (`paciente_id`),
  KEY `idx_orcamento_agendamento` (`agendamento_id`),
  CONSTRAINT `fk_orcamento_agendamento` FOREIGN KEY (`agendamento_id`) REFERENCES `agendamentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orcamentos_ibfk_1` FOREIGN KEY (`paciente_id`) REFERENCES `prontuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orcamentos`
--

LOCK TABLES `orcamentos` WRITE;
/*!40000 ALTER TABLE `orcamentos` DISABLE KEYS */;
INSERT INTO `orcamentos` VALUES (9,4,'2026-08-22','2026-09-21','aceito',0,NULL,'','2026-08-22 01:41:48',NULL),(10,4,'2026-08-22','2026-09-21','aceito',0,NULL,'','2026-08-22 02:08:41',5),(11,4,'2026-08-24','2026-09-23','recusado',0,NULL,'','2026-08-24 12:50:39',NULL),(12,4,'2026-08-24','2026-09-23','recusado',0,NULL,'','2026-08-24 13:02:47',NULL),(13,4,'2026-08-24','2026-09-23','parcialmente_aceito',0,NULL,'','2026-08-24 14:53:09',NULL),(14,4,'2026-08-25','2026-09-24','parcialmente_recusado',0,NULL,'','2026-08-25 13:10:42',NULL),(15,4,'2026-08-26','2026-09-25','aceito',0,NULL,'','2026-08-26 12:59:17',NULL),(16,5,'2026-09-02','2026-10-02','aceito',0,NULL,'','2026-09-02 02:02:14',NULL),(17,4,'2026-09-03','2026-10-03','aceito',0,NULL,'','2026-09-03 15:24:41',NULL),(18,5,'2026-09-08','2026-10-08','aceito',0,NULL,'','2026-09-08 15:29:31',NULL),(19,5,'2026-09-09','2026-10-09','recusado',0,NULL,'','2026-09-09 14:36:38',NULL),(20,5,'2026-09-09','2026-10-09','aceito',0,NULL,'','2026-09-09 14:37:34',NULL),(21,4,'2026-09-15','2026-10-15','aceito',0,NULL,'','2026-09-15 12:58:37',NULL),(22,5,'2026-09-16','2026-10-20','recusado',0,NULL,'','2026-09-16 12:22:22',NULL),(23,4,'2026-09-17','2026-10-17','pendente',1,'2026-09-21 09:43:57','','2026-09-17 12:25:26',NULL),(24,4,'2026-09-22','2026-10-22','pendente',0,NULL,'','2026-09-22 13:40:31',NULL);
/*!40000 ALTER TABLE `orcamentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orcamentos_condicoes`
--

DROP TABLE IF EXISTS `orcamentos_condicoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orcamentos_condicoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orcamento_id` int(11) NOT NULL,
  `numero` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `vencimento_previsto` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orcamento_condicao_numero` (`orcamento_id`,`numero`),
  CONSTRAINT `fk_orcamentos_condicoes_orcamento` FOREIGN KEY (`orcamento_id`) REFERENCES `orcamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orcamentos_condicoes`
--

LOCK TABLES `orcamentos_condicoes` WRITE;
/*!40000 ALTER TABLE `orcamentos_condicoes` DISABLE KEYS */;
INSERT INTO `orcamentos_condicoes` VALUES (1,9,1,100.00,'2026-09-23'),(2,9,2,100.00,'2026-10-22'),(3,9,3,100.00,'2026-11-22'),(4,10,1,100.00,'2026-09-22'),(5,10,2,100.00,'2026-10-22'),(6,10,3,100.00,'2026-11-22'),(7,11,1,300.00,'2026-09-24'),(8,12,1,300.00,'2026-09-24'),(9,13,1,950.00,'2026-09-24'),(11,15,1,600.00,'2026-09-26'),(12,16,1,600.00,'2026-10-02'),(13,17,1,800.00,'2026-10-03'),(14,18,1,51.20,'2026-10-08'),(15,18,2,51.20,'2026-11-08'),(16,18,3,51.20,'2026-12-08'),(17,18,4,51.20,'2027-01-08'),(18,18,5,51.20,'2027-02-08'),(19,19,1,500.00,'2026-10-09'),(20,20,1,500.00,'2026-10-09'),(21,21,1,100.00,'2026-10-15'),(22,21,2,100.00,'2026-11-15'),(23,21,3,100.00,'2026-12-15'),(24,21,4,100.00,'2027-01-15'),(44,22,1,287.50,'2026-10-25'),(45,22,2,287.50,'2026-11-25'),(46,22,3,287.50,'2026-12-25'),(47,22,4,287.50,'2027-01-25'),(64,23,1,400.00,'2026-09-17'),(65,23,2,400.00,'2026-10-17'),(66,23,3,400.00,'2026-11-17'),(67,23,4,400.00,'2026-12-17'),(68,14,1,350.00,'2026-09-25'),(69,24,1,840.00,'2026-09-22');
/*!40000 ALTER TABLE `orcamentos_condicoes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orcamentos_itens`
--

DROP TABLE IF EXISTS `orcamentos_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orcamentos_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orcamento_id` int(11) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `valor_unitario` decimal(10,2) NOT NULL,
  `status` enum('pendente','aceito','recusado') NOT NULL DEFAULT 'pendente',
  PRIMARY KEY (`id`),
  KEY `orcamento_id` (`orcamento_id`),
  CONSTRAINT `orcamentos_itens_ibfk_1` FOREIGN KEY (`orcamento_id`) REFERENCES `orcamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orcamentos_itens`
--

LOCK TABLES `orcamentos_itens` WRITE;
/*!40000 ALTER TABLE `orcamentos_itens` DISABLE KEYS */;
INSERT INTO `orcamentos_itens` VALUES (9,9,'teste',1,300.00,'pendente'),(10,10,'teste',1,300.00,'pendente'),(11,11,'Clareamento',1,300.00,'pendente'),(12,12,'Restauração 02',1,300.00,'pendente'),(13,13,'Clareamento dental',1,800.00,'aceito'),(14,13,'Limpeza dental',1,150.00,'recusado'),(15,14,'Limpeza dental',1,150.00,'pendente'),(16,15,'Restauração',1,450.00,'pendente'),(17,15,'Limpeza dental',1,150.00,'pendente'),(18,16,'Teste',1,600.00,'pendente'),(19,17,'Clareamento dental',1,800.00,'pendente'),(20,18,'Teste 05',1,256.00,'pendente'),(21,19,'Teste',1,500.00,'pendente'),(22,20,'Teste',1,500.00,'pendente'),(23,21,'Restauração',1,400.00,'aceito'),(30,22,'Teste',1,1000.00,'recusado'),(31,22,'Limpeza',1,150.00,'recusado'),(32,23,'Teste 01',1,800.00,'pendente'),(34,23,'Clareamento dental',1,800.00,'pendente'),(37,14,'Restauração',1,200.00,'recusado'),(38,24,'Teste',1,500.00,'pendente'),(39,24,'Teste 02',1,250.00,'pendente'),(40,24,'Aplicação de Selante',1,90.00,'pendente');
/*!40000 ALTER TABLE `orcamentos_itens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parcelas`
--

DROP TABLE IF EXISTS `parcelas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parcelas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orcamento_id` int(11) DEFAULT NULL,
  `procedimento_id` int(11) DEFAULT NULL,
  `numero_parcela` tinyint(4) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `vencimento` date NOT NULL,
  `status` enum('pendente','paga','atrasada') DEFAULT 'pendente',
  `data_pagamento` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_orcamento` (`orcamento_id`),
  KEY `idx_parcelas_procedimento` (`procedimento_id`),
  CONSTRAINT `fk_parcelas_procedimento` FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`),
  CONSTRAINT `parcelas_ibfk_1` FOREIGN KEY (`orcamento_id`) REFERENCES `orcamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parcelas`
--

LOCK TABLES `parcelas` WRITE;
/*!40000 ALTER TABLE `parcelas` DISABLE KEYS */;
INSERT INTO `parcelas` VALUES (20,9,NULL,1,100.00,'2026-09-23','pendente',NULL),(21,9,NULL,2,100.00,'2026-10-22','pendente',NULL),(22,9,NULL,3,100.00,'2026-11-22','pendente',NULL),(23,10,NULL,1,100.00,'2026-09-22','paga','2026-09-01'),(24,10,NULL,2,100.00,'2026-10-22','pendente',NULL),(25,10,NULL,3,100.00,'2026-11-22','pendente',NULL),(26,11,NULL,1,300.00,'2026-09-24','pendente',NULL),(27,12,NULL,1,300.00,'2026-09-24','pendente',NULL),(28,13,NULL,1,950.00,'2026-09-24','pendente',NULL),(29,14,NULL,1,150.00,'2026-09-25','pendente',NULL),(30,15,NULL,1,600.00,'2026-09-26','pendente',NULL),(31,NULL,3,1,83.34,'2026-10-01','paga','2026-09-08'),(32,NULL,3,2,83.33,'2026-11-01','pendente',NULL),(33,NULL,3,3,83.33,'2026-12-01','pendente',NULL),(34,NULL,10,1,33.34,'2026-10-01','paga','2026-08-31'),(35,NULL,10,2,33.33,'2026-09-15','paga','2026-08-31'),(36,NULL,10,3,33.33,'2026-12-01','pendente',NULL),(37,NULL,11,1,100.00,'2026-09-01','paga','2026-09-01'),(38,NULL,11,2,100.00,'2026-10-01','paga','2026-09-09'),(39,NULL,11,3,100.00,'2026-11-01','pendente',NULL),(40,16,NULL,1,600.00,'2026-10-02','pendente',NULL),(41,NULL,12,1,225.00,'2026-10-02','paga','2026-09-02'),(42,NULL,12,2,225.00,'2026-11-02','pendente',NULL),(43,17,NULL,1,800.00,'2026-10-03','pendente',NULL),(44,NULL,17,1,100.00,'2026-09-03','paga','2026-09-03'),(45,18,NULL,1,51.20,'2026-10-08','pendente',NULL),(46,18,NULL,2,51.20,'2026-11-08','pendente',NULL),(47,18,NULL,3,51.20,'2026-12-08','pendente',NULL),(48,18,NULL,4,51.20,'2027-01-08','pendente',NULL),(49,18,NULL,5,51.20,'2027-02-08','pendente',NULL),(50,NULL,15,1,66.67,'2026-09-08','paga','2026-09-08'),(51,NULL,15,2,66.67,'2026-09-11','atrasada',NULL),(52,NULL,15,3,66.66,'2026-11-08','pendente',NULL),(53,NULL,16,1,133.34,'2026-10-09','pendente',NULL),(54,NULL,16,2,133.33,'2026-11-09','paga','2026-09-09'),(55,NULL,16,3,133.33,'2026-12-09','pendente',NULL),(56,19,NULL,1,500.00,'2026-10-09','pendente',NULL),(57,20,NULL,1,500.00,'2026-10-09','pendente',NULL),(58,NULL,14,1,250.00,'2026-09-13','paga','2026-09-14'),(59,NULL,14,2,250.00,'2026-11-14','pendente',NULL),(60,NULL,14,3,250.00,'2026-12-14','pendente',NULL),(61,NULL,14,4,250.00,'2027-01-14','pendente',NULL),(62,21,NULL,1,100.00,'2026-10-15','pendente',NULL),(63,21,NULL,2,100.00,'2026-11-15','pendente',NULL),(64,21,NULL,3,100.00,'2026-12-15','pendente',NULL),(65,21,NULL,4,100.00,'2027-01-15','pendente',NULL),(66,22,NULL,1,333.33,'2026-10-16','pendente',NULL),(67,22,NULL,2,333.33,'2026-11-16','pendente',NULL),(68,22,NULL,3,333.34,'2026-12-16','pendente',NULL),(69,NULL,19,1,50.00,'2026-10-24','pendente',NULL),(70,NULL,19,2,50.00,'2026-11-24','pendente',NULL);
/*!40000 ALTER TABLE `parcelas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `planos_tratamento`
--

DROP TABLE IF EXISTS `planos_tratamento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planos_tratamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paciente_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `status` enum('planejamento','em_andamento','concluido','cancelado') NOT NULL DEFAULT 'planejamento',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_planos_tratamento_paciente` (`paciente_id`),
  KEY `idx_planos_tratamento_status` (`status`),
  CONSTRAINT `fk_planos_tratamento_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `prontuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `planos_tratamento`
--

LOCK TABLES `planos_tratamento` WRITE;
/*!40000 ALTER TABLE `planos_tratamento` DISABLE KEYS */;
INSERT INTO `planos_tratamento` VALUES (1,4,'Plano de Reabilitação Oral','Planejamento inicial do tratamento do paciente','concluido','2026-08-25 14:20:23','2026-08-26 21:32:19');
/*!40000 ALTER TABLE `planos_tratamento` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `planos_tratamento_itens`
--

DROP TABLE IF EXISTS `planos_tratamento_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planos_tratamento_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano_id` int(11) NOT NULL,
  `servico_id` int(11) DEFAULT NULL,
  `descricao` varchar(255) NOT NULL,
  `dente_regiao` varchar(100) DEFAULT NULL,
  `prioridade` enum('baixa','media','alta') NOT NULL DEFAULT 'media',
  `valor_estimado` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('planejado','em_andamento','concluido','cancelado') NOT NULL DEFAULT 'planejado',
  `ordem` int(11) NOT NULL DEFAULT 0,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_plano_itens_plano` (`plano_id`),
  KEY `idx_plano_itens_servico` (`servico_id`),
  KEY `idx_plano_itens_status` (`status`),
  KEY `idx_plano_itens_ordem` (`plano_id`,`ordem`),
  CONSTRAINT `fk_plano_itens_plano` FOREIGN KEY (`plano_id`) REFERENCES `planos_tratamento` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_plano_itens_servico` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `planos_tratamento_itens`
--

LOCK TABLES `planos_tratamento_itens` WRITE;
/*!40000 ALTER TABLE `planos_tratamento_itens` DISABLE KEYS */;
INSERT INTO `planos_tratamento_itens` VALUES (7,1,1,'Restauração','16','alta',400.00,'concluido',1,'Avaliar necessidade de restauração adicional'),(8,1,2,'Profilaxia','Arcada completa','media',150.00,'concluido',2,NULL);
/*!40000 ALTER TABLE `planos_tratamento_itens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `planos_tratamento_itens_orcamentos`
--

DROP TABLE IF EXISTS `planos_tratamento_itens_orcamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planos_tratamento_itens_orcamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano_item_id` int(11) NOT NULL,
  `orcamento_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plano_item_orcamento` (`plano_item_id`,`orcamento_id`),
  KEY `idx_ptio_plano_item` (`plano_item_id`),
  KEY `idx_ptio_orcamento` (`orcamento_id`),
  CONSTRAINT `fk_ptio_orcamento` FOREIGN KEY (`orcamento_id`) REFERENCES `orcamentos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ptio_plano_item` FOREIGN KEY (`plano_item_id`) REFERENCES `planos_tratamento_itens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `planos_tratamento_itens_orcamentos`
--

LOCK TABLES `planos_tratamento_itens_orcamentos` WRITE;
/*!40000 ALTER TABLE `planos_tratamento_itens_orcamentos` DISABLE KEYS */;
INSERT INTO `planos_tratamento_itens_orcamentos` VALUES (2,7,21,'2026-09-15 12:58:37');
/*!40000 ALTER TABLE `planos_tratamento_itens_orcamentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `procedimento_materiais`
--

DROP TABLE IF EXISTS `procedimento_materiais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `procedimento_materiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `procedimento_id` int(11) NOT NULL,
  `estoque_id` int(11) NOT NULL,
  `quantidade` decimal(10,2) NOT NULL,
  `valor_unitario` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_procedimento` (`procedimento_id`),
  KEY `idx_estoque` (`estoque_id`),
  CONSTRAINT `fk_procedimento_materiais_estoque` FOREIGN KEY (`estoque_id`) REFERENCES `estoque` (`id`),
  CONSTRAINT `fk_procedimento_materiais_procedimento` FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `procedimento_materiais`
--

LOCK TABLES `procedimento_materiais` WRITE;
/*!40000 ALTER TABLE `procedimento_materiais` DISABLE KEYS */;
INSERT INTO `procedimento_materiais` VALUES (4,3,1,1.00,15.00,15.00,'2026-08-24 12:09:28'),(5,10,2,1.00,1.50,1.50,'2026-08-31 13:41:43'),(6,9,2,1.00,1.50,1.50,'2026-08-31 15:18:42'),(7,11,1,1.00,15.00,15.00,'2026-09-01 14:26:15'),(8,12,2,1.00,1.50,1.50,'2026-09-02 02:07:01'),(13,17,2,1.00,1.50,1.50,'2026-09-03 15:26:01'),(14,15,1,2.00,15.00,30.00,'2026-09-08 15:31:01'),(15,16,1,2.00,15.00,30.00,'2026-09-09 14:29:59'),(16,16,2,2.00,1.50,3.00,'2026-09-09 14:29:59'),(18,14,1,1.00,15.00,15.00,'2026-09-14 12:30:53'),(19,19,4,1.00,25.50,25.50,'2026-09-24 00:53:12');
/*!40000 ALTER TABLE `procedimento_materiais` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `procedimentos`
--

DROP TABLE IF EXISTS `procedimentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `procedimentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paciente_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `medicamentos` text DEFAULT NULL,
  `valor_materiais` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_mao_obra` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_final` decimal(10,2) NOT NULL DEFAULT 0.00,
  `data_procedimento` date NOT NULL,
  `orcamento_id` int(11) DEFAULT NULL,
  `plano_item_id` int(11) DEFAULT NULL,
  `agendamento_id` int(11) DEFAULT NULL,
  `arquivado` tinyint(1) NOT NULL DEFAULT 0,
  `arquivado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `paciente_id` (`paciente_id`),
  KEY `idx_procedimento_orcamento` (`orcamento_id`),
  KEY `idx_procedimento_plano_item` (`plano_item_id`),
  KEY `idx_procedimento_agendamento` (`agendamento_id`),
  CONSTRAINT `fk_procedimento_agendamento` FOREIGN KEY (`agendamento_id`) REFERENCES `agendamentos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_procedimento_orcamento` FOREIGN KEY (`orcamento_id`) REFERENCES `orcamentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_procedimento_plano_item` FOREIGN KEY (`plano_item_id`) REFERENCES `planos_tratamento_itens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `procedimentos_ibfk_1` FOREIGN KEY (`paciente_id`) REFERENCES `prontuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `procedimentos`
--

LOCK TABLES `procedimentos` WRITE;
/*!40000 ALTER TABLE `procedimentos` DISABLE KEYS */;
INSERT INTO `procedimentos` VALUES (3,4,'Restauração','Teste de procedimento',NULL,15.00,130.00,250.00,'2026-08-21',10,NULL,NULL,0,NULL),(5,4,'Restauração',NULL,NULL,0.00,0.00,0.00,'2026-08-26',NULL,7,13,0,NULL),(6,4,'Restauração',NULL,NULL,0.00,0.00,0.00,'2026-08-26',NULL,7,13,0,NULL),(7,4,'Restauração',NULL,NULL,0.00,0.00,0.00,'2026-08-26',NULL,7,13,0,NULL),(8,4,'Restauração',NULL,NULL,0.00,0.00,0.00,'2026-08-26',NULL,7,13,0,NULL),(9,4,'Limpeza dental','Avaliação clínica e procedimento de teste realizado com sucesso.','Nenhum medicamento utilizado.',1.50,100.00,150.00,'2026-08-26',NULL,8,NULL,0,NULL),(10,5,'Consulta','Teste de atendimento realizado às 20 horas.','Nenhum medicamento utilizado.',1.50,100.00,100.00,'2026-08-26',NULL,NULL,NULL,0,NULL),(11,5,'Clareamento','Clareamento nos dentes primarios','nenhum medicamento',15.00,300.00,300.00,'2026-09-01',NULL,NULL,NULL,0,NULL),(12,5,'Teste','Teste','nenhum medicamento',1.50,400.00,450.00,'2026-09-02',NULL,NULL,NULL,1,'2026-09-23 17:05:49'),(13,4,'Consulta',NULL,NULL,0.00,0.00,0.00,'2026-08-27',NULL,NULL,6,0,NULL),(14,5,'teste',NULL,NULL,15.00,0.00,1000.00,'2026-09-01',NULL,NULL,NULL,0,NULL),(15,5,'teste 02','Teste de Registro',NULL,30.00,150.00,200.00,'2026-09-02',NULL,NULL,NULL,0,NULL),(16,5,'Teste 04',NULL,NULL,33.00,0.00,400.00,'2026-09-02',NULL,NULL,NULL,0,NULL),(17,4,'Teste',NULL,NULL,1.50,0.00,100.00,'2026-09-03',NULL,NULL,NULL,0,NULL),(19,5,'Teste06','Teste',NULL,25.50,0.00,100.00,'2026-09-24',NULL,NULL,NULL,1,'2026-09-23 21:55:00');
/*!40000 ALTER TABLE `procedimentos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prontuarios`
--

DROP TABLE IF EXISTS `prontuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prontuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paciente` varchar(255) NOT NULL,
  `nascimento` date NOT NULL,
  `sexo` enum('Masculino','Feminino','Outro') DEFAULT NULL,
  `estado_civil` varchar(50) DEFAULT NULL,
  `profissao` varchar(100) DEFAULT NULL,
  `rg` varchar(20) DEFAULT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `cep` varchar(10) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `tratamento_odonto` text DEFAULT NULL,
  `tratamento_medico` text DEFAULT NULL,
  `medicamento_continuo` text DEFAULT NULL,
  `alergia_medicamento` text DEFAULT NULL,
  `alergia_outras` text DEFAULT NULL,
  `problemas_saude` text DEFAULT NULL,
  `gravida_meses` varchar(10) DEFAULT NULL,
  `fuma_tempo` varchar(50) DEFAULT NULL,
  `fuma_cigarros_dia` varchar(20) DEFAULT NULL,
  `bebida_frequencia` varchar(100) DEFAULT NULL,
  `drogas_uso` text DEFAULT NULL,
  `doencas_transmissiveis` text DEFAULT NULL,
  `cancer_familiar` text DEFAULT NULL,
  `tratamento_cancer` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `termo_consentimento_aceito` tinyint(1) NOT NULL DEFAULT 0,
  `termo_consentimento_aceito_em` datetime DEFAULT NULL,
  `arquivado` tinyint(1) NOT NULL DEFAULT 0,
  `arquivado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prontuarios_cpf` (`cpf`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prontuarios`
--

LOCK TABLES `prontuarios` WRITE;
/*!40000 ALTER TABLE `prontuarios` DISABLE KEYS */;
INSERT INTO `prontuarios` VALUES (4,'Paula','2001-12-05','Feminino','','','',NULL,'','','(18) 98800-1498','','','','','','','','','','','','','','','','','2026-08-21 11:36:17',1,'2026-08-21 11:36:22',0,NULL),(5,'Gustavo','2004-03-04','Masculino','Solteiro','Desenvolvedor','','40048760870','Torres Homem 1298 Vila Santa Maria','16015-625','(18) 99786-6548','cerizza57@gmail.com','','','','','','','','','','','','','','','','2026-08-26 16:41:05',1,'2026-08-26 16:41:10',0,NULL),(6,'Samuel Stabile','1975-11-18','Masculino','','','',NULL,'','','','samuelstabile75@gmail.com','','','','','','','','','','','','','','','','2026-09-04 14:21:31',1,'2026-09-04 14:21:57',0,NULL),(7,'Francisco Almeida Cavalcanti','1986-10-19','Masculino','Casado','Serralheiro','','30694139009','Travessa Honorato e Silva 256 Parque Santa Maria','60873-061','(19) 98049-8706','tobiasprata820@gmail.com','','','','','','','','','','','','','','','','2026-09-22 14:26:14',0,NULL,0,NULL),(8,'Oscar Estellet Cunha Auzier','1985-11-03','Masculino','Casado','Repositor de mercadorias','','99712307808','Rua Chile 125 Jardim Brasília','16025-610','(18) 99142-2840','osc_est85@gmail.com.br','','','','','','','','','','','','','','','','2026-09-25 14:39:14',0,NULL,0,NULL);
/*!40000 ALTER TABLE `prontuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `servicos`
--

DROP TABLE IF EXISTS `servicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `servicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `valor_sugerido` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_servicos_ativo` (`ativo`),
  KEY `idx_servicos_nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `servicos`
--

LOCK TABLES `servicos` WRITE;
/*!40000 ALTER TABLE `servicos` DISABLE KEYS */;
INSERT INTO `servicos` VALUES (1,'Restauração Resina Complexa','Restauração dentária em resina composta (múltiplas faces)',500.00,1,'2026-08-24'),(2,'Limpeza dental','Profilaxia, raspagem de tártaro e aplicação de flúor',200.00,1,'2026-08-24'),(3,'Clareamento dental','Clareamento odontológico caseiro ou de consultório',800.00,1,'2026-08-24'),(4,'Restauração Resina Simples','Restauração dentária em resina composta (1 face)',350.00,1,'2026-08-24'),(5,'Consulta Inicial + Avaliação','Avaliação clínica geral com anamnese e exame intraoral',150.00,1,'2026-09-09'),(6,'Extração Dentária Simples','Exodontia de dente permanente monorradicular',250.00,1,'2026-09-09'),(7,'Tratamento de Canal (Endodontia)','Tratamento endodôntico em dente anterior',650.00,1,'2026-09-09'),(8,'Tratamento de Canal Molar','Tratamento endodôntico em dente posterior (molar)',950.00,1,'2026-09-09'),(9,'Aplicação de Selante','Prevenção de cáries em sulcos e fissuras (por dente)',90.00,1,'2026-09-09'),(10,'Coroa Total em Zircônia','Prótese fixa unitária computadorizada (CAD/CAM)',1800.00,1,'2026-09-09'),(11,'Radiografia Periapical','Exame radiográfico intraoral unitário para diagnóstico',45.00,1,'2026-09-09');
/*!40000 ALTER TABLE `servicos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'evoluqi'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-02 12:33:19
