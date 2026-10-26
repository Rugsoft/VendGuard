/**
 * VendGuard - DateTimeFormat Utility (DateTimeFormat.js)
 *
 * Formateo tolerante de marcas de tiempo del servidor para interfaces web
 * y móviles. Resuelve diferencias de formato de PostgreSQL/TIMESTAMPTZ
 * (zonas con offset corto '+00', espacios en lugar de 'T', UTC sin sufijo)
 * y devuelve siempre un formato legible en castellano ('es-ES') o un guión ('—').
 *
 * Dogma Vanilla: función pura, sin dependencias externas.
 */

/**
 * Normaliza y formatea una fecha/hora ISO o SQL a formato local 'dd/mm/aaaa hh:mm'.
 *
 * @param {string|number|Date|null|undefined} value
 * @returns {string} Fecha formateada o '—' si es nula/inválida.
 */
export function formatDateTime(value) {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  if (value instanceof Date) {
    if (Number.isNaN(value.getTime())) {
      return '—';
    }
    return formatValidDate(value);
  }

  let text = String(value).trim();
  if (text === '') {
    return '—';
  }

  // Reemplazar espacio entre fecha y hora por 'T' si no tiene ya 'T'
  if (!text.includes('T') && /^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(text)) {
    text = text.replace(/\s+/, 'T');
  }

  // Corregir offset de zona horaria corta estilo PostgreSQL: '+00' o '-03' a '+00:00' o '-03:00'
  text = text.replace(/([+-]\d{2})$/, '$1:00');

  // Corregir offset de 4 dígitos pegados: '+0000' a '+00:00'
  text = text.replace(/([+-]\d{2})(\d{2})$/, '$1:$2');

  // Si no tiene indicador de zona horaria (Z o [+-]HH:MM) ni offset, y es ISO fecha+hora, tratar como UTC
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(text)) {
    text += 'Z';
  }

  const parsed = new Date(text);
  if (Number.isNaN(parsed.getTime())) {
    return '—';
  }

  return formatValidDate(parsed);
}

function formatValidDate(date) {
  return new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(date);
}

export default formatDateTime;
