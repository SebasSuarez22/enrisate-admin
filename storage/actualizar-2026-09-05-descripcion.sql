-- ENRÍSATE · Agrega la columna `descripcion` a productos
-- =====================================================
-- Importar UNA SOLA VEZ desde phpMyAdmin (pestaña SQL) sobre la base
-- enrisate_admin. Después de esto, cada producto puede tener su propia
-- descripción, editable desde Productos. Mientras no se importe, el
-- botón de descripción sigue ahí pero lo que se escriba no se guarda
-- (Productos avisa esto en pantalla).

ALTER TABLE productos
  ADD COLUMN descripcion TEXT NOT NULL DEFAULT '';
