EM Vēstuļu Draugs (PHP) - subdir /embpd/

1) Ieliec visu mapē, kas servējas kā https://add4live.com/embpd/
2) Atver .env.php un ieliec OPENAI_API_KEY
3) Iekopē normatīvos .txt failus mapē data/normativi/
4) Pārliecinies, ka data/ ir rakstāma (www-data)

Sākuma intent_rules (SQLite):
INSERT INTO intent_rules (intent, rule_type, pattern, priority, note) VALUES
('eka','contains','dzivojama eka',10,'ēka'),
('eka','contains','dzivojamo eka',20,'ēka'),
('eka','contains','eka',50,'ēka vispārīgi'),
('eka','regex','\\bmaj\\w*\\b',60,'māja, mājas, mājai...'),
('eka','contains','privatmaja',70,'privātmāja'),
('eka','contains','viena dzivokla',80,'viena dzīvokļa'),
('inzenierbuve','contains','inzenierbuve',10,'inženierbūve'),
('inzenierbuve','contains','inzenierbuv',20,'inženierbūves sakne');
