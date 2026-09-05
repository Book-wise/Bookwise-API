---
name: backend-change-spec
description: "Trigger: backend change, feature spec, implementation task, tarea backend, cambio backend. Define backend changes for PHP/Laravel projects using structured specification format with business rules, scope boundaries, and verification criteria."
license: Apache-2.0
metadata:
  author: "kinesilk-team"
  version: "1.0"
---

## Activation Contract

Invoke this skill when defining ANY backend change for this Laravel project — before writing code, before creating tasks, before delegating implementation. This includes new features, webhook handlers, service layers, model changes, API endpoints, and integration work.

The skill structures the SPECIFICATION you hand to an implementing agent. It ensures the agent has enough context to build correctly without making assumptions.

## Hard Rules

- Always include ALL nine sections below. Omitting one creates a gap the agent will fill with assumptions.
- Read the existing codebase before writing Section 4 (archivos existentes) — never guess file paths or existing logic.
- Write the specification in the same language as the user's request. Default: Spanish for Kinesilk.
- The implementing agent receives this spec as-is — include enough detail that they DO NOT need to ask clarifying questions.

## Decision Gates

| Signal | Action |
|--------|--------|
| Change touches 3+ files | Include Section 6 (archivos a crear) with exact method signatures |
| Change has rollback or conflict logic | Make Section 3 (reglas de negocio) a decision table |
| Change modifies an existing endpoint | Include Section 7 (archivos a modificar) with exact line/area references |
| No existing tests for the affected area | Add a note in Section 8 (pruebas) about manual verification |
| Change affects auth/permissions/webhook signatures | Put it in Section 9 (lo que no debe cambiar) as a hard boundary |

## Execution Steps

### 1. Entender el cambio
- Hablar con el usuario para entender el flujo completo: ¿qué dispara el cambio? ¿qué datos entran? ¿qué sale? ¿qué pasa si falla?
- Identificar el alcance: ¿es nuevo endpoint? ¿nuevo servicio? ¿modificación de flujo existente?
- Identificar el dominio: pagos, reservas, disponibilidad, clientes, etc.

### 2. Leer el código existente
- Buscar modelos, controladores, servicios, migraciones relacionados
- Entender relaciones Eloquent, scopes, casts
- Identificar patrones existentes a seguir y gaps actuales

### 3. Redactar la especificación usando esta estructura:

## Especificación: {nombre del cambio}

### 1. Contexto del proyecto
Contexto breve: qué sistema, qué integración, por qué existe este cambio.

### 2. Problema actual / Qué está mal hoy
Qué NO funciona o qué gap existe hoy. Incluir comportamiento incorrecto si aplica.

### 3. Comportamiento deseado
Lista numerada de pasos que el sistema debe ejecutar, EN ORDEN. Cada paso es una responsabilidad atómica. Incluir ejemplos de payloads donde sea relevante.

### 4. Reglas de negocio
Tabla con: Regla | Comportamiento | Código HTTP (si aplica)

Incluir:
- Idempotencia (cómo evitar duplicados)
- Manejo de conflictos (qué pasa cuando choca con datos existentes)
- Casos borde (datos faltantes, clientes duplicados, timeouts)
- Validaciones de negocio (no técnicas)

### 5. Archivos existentes (leer antes de modificar)
Lista de archivos relevantes con UNA línea de contexto cada uno:
`path/al/archivo.php` — qué contiene y por qué importa para este cambio

### 6. Archivos a crear
Para cada archivo nuevo:
- `app/Services/NuevoService.php` — métodos: `metodoUno(array $data): Tipo`, `metodoDos(int $id): ?Tipo`
- Incluir firmas de métodos y breve descripción de cada uno

### 7. Archivos a modificar
Para cada archivo existente:
- `path/al/archivo.php` — qué cambiar (sección específica: método X, handler Y, flujo Z)
- `path/al/otro.php` — agregar nueva rama en el switch/caso, o refactorizar método completo

### 8. Pruebas / Verificación
Cómo verificar que el cambio funciona:
- Pasos concretos para probar (endpoint, payload, respuesta esperada)
- Si hay tests automatizados, mencionar comando
- Si no hay tests, cómo validar con datos reales

### 9. Lo que NO debe cambiar
Límites explícitos: esto no se toca, esto no se refactoriza, esto sigue igual. Proteger:
- Autenticación y middleware existentes
- Estructura de tablas consolidadas
- Endpoints que ya funcionan y no están en alcance
- Firmas de métodos públicos si hay consumidores externos

## Output Contract

Return the complete specification in the nine-section format above. The specification must be:
- **Auto-contenida**: el agente que la recibe no necesita preguntar nada más
- **Ejecutable**: cada paso es accionable, no ambiguo
- **Validada**: las reglas de negocio cubren casos borde y conflictos
- **Acotada**: la sección 9 deja claro qué NO se hace

## References

- `.agents/skills/laravel-best-practices/SKILL.md` — Laravel conventions for this project
- `app/Services/IdempotencyService.php` — patrón de idempotencia existente
- `app/Http/Controllers/Api/V1/WebhookController.php` — ejemplo de handler con HMAC + logging
