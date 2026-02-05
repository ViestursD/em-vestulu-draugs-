-- ✅ Import mazeka_ar_pilsetas rules (≤25 m², no kultūras pieminekļa)
-- Šie ieraksti atbilst 1. grupas mazēkām ārpus pilsētas, kur dokumenti nav nepieciešami

INSERT INTO `embpd_decision_rules` 
  (`id`, `buves_tips`, `buves_grupa`, `objekts`, `darbiba`, `doc_type`, `normative_file`, `atsauce`, `priority`, `enabled`, `note`, `kulturas_piemineklis`, `area_limit`, `tag`) 
VALUES
  (76, 'eka', '1', 'mazeka_ar_pilsetas', 'jaunbuvnieciba', 'Būvniecības ieceres dokumenti nav nepieciešami', 'eku-buvnoteikumi.txt', '7.3.4', 26, 1, 'ĒBN 7.3.4: mazēka ārpus pilsētas jaunbūvniecība (ne kultūras piem. teritorija)', 'no', 25, '<=25_outside_city'),
  (77, 'eka', '1', 'mazeka_ar_pilsetas', 'novietosana', 'Būvniecības ieceres dokumenti nav nepieciešami', 'eku-buvnoteikumi.txt', '7.3.4', 27, 1, 'ĒBN 7.3.4: mazēka ārpus pilsētas novietošana (ne kultūras piem. teritorija)', 'no', 25, '<=25_outside_city'),
  (78, 'eka', '1', 'mazeka_ar_pilsetas', 'nojauksana', 'Būvniecības ieceres dokumenti nav nepieciešami', 'eku-buvnoteikumi.txt', '7.3.4', 28, 1, 'ĒBN 7.3.4: mazēka ārpus pilsētas nojaukšana (ne kultūras piem. teritorija)', 'no', 25, '<=25_outside_city')
ON DUPLICATE KEY UPDATE 
  `doc_type` = VALUES(`doc_type`),
  `priority` = VALUES(`priority`),
  `atsauce` = VALUES(`atsauce`),
  `note` = VALUES(`note`);

-- ✅ Parīnfirmēt, ka ieraksti ir importēti
SELECT id, objekts, darbiba, doc_type, priority, enabled FROM `embpd_decision_rules` WHERE objekts='mazeka_ar_pilsetas' ORDER BY id;
