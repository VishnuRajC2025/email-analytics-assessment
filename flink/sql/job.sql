-- Flink SQL Job: Kafka -> XAMPP MySQL stream processing

CREATE TABLE ks (event_id STRING, campaign_id STRING, type STRING, event_timestamp STRING) WITH ('connector'='kafka','topic'='email-events','properties.bootstrap.servers'='kafka:9092','properties.group.id'='flink-xampp','scan.startup.mode'='earliest-offset','format'='json','json.ignore-parse-errors'='true');

CREATE TABLE ms (event_id STRING, campaign_id STRING, type STRING, event_timestamp STRING, PRIMARY KEY (event_id) NOT ENFORCED) WITH ('connector'='jdbc','url'='jdbc:mysql://host.docker.internal:3306/email_analytics?useSSL=false&allowPublicKeyRetrieval=true','table-name'='events','username'='root','password'='','sink.buffer-flush.max-rows'='100','sink.buffer-flush.interval'='1s');

INSERT INTO ms SELECT event_id, campaign_id, type, event_timestamp FROM ks;
