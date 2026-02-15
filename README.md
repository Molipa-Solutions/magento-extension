# TML Shipping for Magento 2

Integración oficial de **TML** para Magento 2.

Este módulo permite conectar tu tienda Magento con los servicios logísticos de TML, facilitando el cálculo de tarifas, envío de eventos y sincronización de operaciones.

---

## 📦 Características

- Integración directa con la API de TML
- Cálculo de tarifas de envío en el checkout
- Sistema de reintento automático (Outbox Pattern)
- Comando CLI para reintentos manuales
- Soporte para múltiples websites
- Compatible con Magento 2.4.x

---

## 🧩 Requisitos

- Magento 2.4.x
- PHP 7.4 o superior
- Acceso a servicios TML
- Cron de Magento correctamente configurado y ejecutándose

> ⚠️ **IMPORTANTE**
>
> El cron de Magento debe estar configurado y funcionando correctamente.
>
> Para instalar el cron en el servidor, ejecutar:
>
>     php bin/magento cron:install
>
> Luego verificar que esté activo:
>
>     crontab -l
>
> Si el cron no está activo:
>
> - Los eventos pendientes no serán procesados.
> - Los reintentos automáticos no se ejecutarán.
> - Puede haber inconsistencias entre Magento y los sistemas de TML.
>
> En entornos productivos se recomienda que el cron del sistema operativo
> ejecute Magento cada minuto.

---

## 🚀 Instalación
> ⚠️ **IMPORTANTE**
>
> Mantenimiento: Se recomienda activar el modo mantenimiento antes de comenzar para 
> evitar inconsistencias en la base de datos y errores visuales a los usuarios.

### 1️⃣ Activar Modo Mantenimiento

```bash
  php bin/magento maintenance:enable
```

### 2️⃣ Instalar vía Composer

```bash
  composer require tml/module-shipping
```
### 3️⃣ Habilitar el módulo

```bash
    php bin/magento module:enable Molipa_TmlShipping
    php bin/magento setup:upgrade
```

### 4️⃣ (Solo en modo producción)
Si la tienda se encuentra en modo producción, ejecutar:
```bash
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy -f
```

### 5️⃣ Limpiar Cache
```bash
    php bin/magento cache:flush
```
---

## ⚙ Configuración

> ⚠️ **IMPORTANTE**
>
> Toda la configuración del módulo se realiza **por Website**.
> Asegurate de seleccionar el Website correcto en el selector de alcance (Scope) antes de realizar cualquier cambio.
>
> Las credenciales configuradas en  
> **Stores → Configuration → Sales → TML Shipping**  
> **NO deben modificarse manualmente**, ya que son gestionadas automáticamente por el sistema de TML.

1. Ir a:
   **Stores → Configuration → Sales → TML Shipping**
2. Habilitar el módulo.
3. Guardar la configuración.
4. Ir a:
   **Stores → Configuration → Sales → Delivery Methods → TML**
5. Asegurarse que esté habilitado (caso contrario, habilitar y guardar configuración).

---

## 🔁 Reintento manual de envíos

El módulo incluye un comando CLI para reintentar eventos pendientes:

    php bin/magento tmlshipping:retry-outbox

Este comando procesa los eventos pendientes en la tabla de outbox e intenta reenviarlos a los servicios externos de TML.

---

## 🕒 Cron

El módulo utiliza el sistema de cron de Magento para procesar reintentos automáticos.

Asegurate de que el cron de Magento esté configurado correctamente:

    php bin/magento cron:run

En entornos productivos se recomienda configurar el cron del sistema operativo para ejecutar Magento cada minuto.

---

## 🧠 Funcionamiento

El módulo integra Magento con los servicios de TML para calcular tarifas de envío en tiempo real y garantizar la entrega confiable de eventos hacia los sistemas externos.

### Cálculo de tarifas

- Durante el proceso de checkout, el módulo consulta la API de TML.
- Se calculan las tarifas de envío en base a los datos del pedido (destino, peso, productos, etc.).
- Las opciones de envío devueltas por TML se muestran directamente al cliente en la tienda.

### Envío confiable de eventos (Outbox Pattern)

El módulo utiliza un patrón **Outbox** para asegurar la comunicación con los servicios externos:

- Cuando ocurre un evento relevante (por ejemplo, creación de un envío), se registra en una tabla interna.
- El sistema de cron procesa periódicamente estos eventos pendientes.
- Si una solicitud falla, será reintentada automáticamente.
- También puede forzarse el reintento manual mediante CLI.

Este enfoque mejora la resiliencia ante fallos de red o indisponibilidad temporal del servicio externo, asegurando consistencia operativa entre Magento y TML.

---

## 🏢 Soporte

Este módulo es distribuido oficialmente por **TML**.

Para soporte técnico o consultas, contactar al equipo de TML a través de los canales oficiales.

---

## 📜 Licencia

Copyright (c) 2026 **TML**. Todos los derechos reservados.  
Desarrollado por **Molipa** para TML.

Este software es propiedad de TML y se proporciona bajo una **licencia propietaria** limitada.  
Su uso, copia o distribución está estrictamente prohibido sin la autorización previa por escrito de TML.