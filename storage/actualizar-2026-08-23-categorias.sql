-- ENRÍSATE · Agrega la columna `categoria` a productos
-- =====================================================
-- Importar UNA SOLA VEZ desde phpMyAdmin (pestaña SQL) sobre la base
-- enrisate_admin. Después de esto, el desplegable de Productos guarda
-- la categoría de verdad en vez de solo deducirla del nombre.
--
-- El relleno inicial usa las mismas reglas y el mismo orden que
-- categoria_deducida() en includes/categorias.php, para que ambos den
-- siempre el mismo resultado. Los productos que no calcen ninguna regla
-- quedan con categoria = '' (el catálogo seguirá deduciéndola del
-- nombre al vuelo, igual que antes de importar esto).

ALTER TABLE productos
  ADD COLUMN categoria VARCHAR(30) NOT NULL DEFAULT '' AFTER nombre;

UPDATE productos SET categoria = 'Shampoo'        WHERE categoria = '' AND nombre REGEXP 'shampoo|champu|champú';
UPDATE productos SET categoria = 'Acondicionador' WHERE categoria = '' AND nombre REGEXP 'acondicionador';
UPDATE productos SET categoria = 'Tratamiento'    WHERE categoria = '' AND nombre REGEXP 'mascarilla|tratamiento|ampolla|keratina|botox';
UPDATE productos SET categoria = 'Crema'          WHERE categoria = '' AND nombre REGEXP 'crema';
UPDATE productos SET categoria = 'Definición'     WHERE categoria = '' AND nombre REGEXP 'gel|gelatina|espuma|mousse';
UPDATE productos SET categoria = 'Aceite'         WHERE categoria = '' AND nombre REGEXP 'aceite|oleo|óleo|serum|sérum|tonico|tónico|elixir';
UPDATE productos SET categoria = 'Accesorio'      WHERE categoria = '' AND nombre REGEXP 'cepillo|peine|secador|plancha|gorro|accesor';
UPDATE productos SET categoria = 'Cuidado'        WHERE categoria = '' AND nombre REGEXP 'jabon|jabón|desodorante';
UPDATE productos SET categoria = 'Spray'          WHERE categoria = '' AND nombre REGEXP 'spray|desenredante';
UPDATE productos SET categoria = 'Protector'      WHERE categoria = '' AND nombre REGEXP 'termoprotector|protector';
UPDATE productos SET categoria = 'Mantequilla'    WHERE categoria = '' AND nombre REGEXP 'mantequilla|manteca';
UPDATE productos SET categoria = 'Reconstructor'  WHERE categoria = '' AND nombre REGEXP 'plex|repolarizador|reconstructor';
UPDATE productos SET categoria = 'Kit'            WHERE categoria = '' AND nombre REGEXP '[[:<:]]kit[[:>:]]|duo|combo';
UPDATE productos SET categoria = 'Niños'          WHERE categoria = '' AND nombre REGEXP '[[:<:]]kids[[:>:]]|niños|ninos|infantil';
