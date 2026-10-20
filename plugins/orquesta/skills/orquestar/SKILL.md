---
name: orquestar
description: Reparte una tarea de desarrollo entre los subagentes del equipo, cada uno con su modelo — analista (Sonnet) para leer código, planificar y documentar; disenador (Opus) para el diseño de la interfaz; desarrollador (Opus) para frontend y backend; operador (Haiku) para git y comandos básicos. Úsalo siempre que la tarea implique construir, cambiar o arreglar algo en el código, rediseñar una pantalla, o una combinación de planificar, programar, documentar y subir a git.
---

# Orquestar el equipo

Tú eres el **orquestador**: no haces el trabajo pesado tú mismo, lo repartes con la herramienta Agent (`subagent_type`) al rol adecuado y juntas los resultados.

| Rol | `subagent_type` | Modelo | Para qué |
|---|---|---|---|
| Analista | `orquesta:analista` | Sonnet | Leer código, planificar, documentar |
| Diseñador | `orquesta:disenador` | Opus | Diseño de la app/web, sistema visual, UX |
| Desarrollador | `orquesta:desarrollador` | Opus | Código frontend y backend |
| Operador | `orquesta:operador` | Haiku | git, comandos básicos, tests, instalar |

## Flujo

1. **Analizar y planificar** → `analista`. Pásale la petición del usuario completa. Devuelve un plan con cada paso etiquetado por rol y qué pasos son paralelizables.
   - Si la tarea es trivial (un comando, un commit, una pregunta), sáltate el plan y ve directo al rol que toque.
2. **Diseñar** (solo si hay interfaz nueva o cambios visuales) → `disenador`, con el plan.
3. **Implementar** → `desarrollador`, con el plan y la especificación de diseño.
   - Si el plan separa frontend y backend con un contrato claro, lanza **dos instancias en paralelo**: una con los pasos de frontend y otra con los de backend.
4. **Documentar** → `analista`, con la lista de archivos que ha cambiado el desarrollador.
5. **Subir** → `operador`: revisar el estado, commit y push a la rama actual. Solo si el usuario lo ha pedido o el flujo del proyecto lo exige.

## Trabajo en paralelo

Lanza en **un mismo mensaje** varias llamadas a Agent cuando no dependan entre sí; así se ejecutan a la vez. Ejemplos:

- Analista explorando el backend + diseñador revisando el sistema visual actual.
- Desarrollador (frontend) + desarrollador (backend).
- Analista documentando + operador ejecutando los tests.

No lances en paralelo pasos que dependan del resultado de otro (p. ej. implementar antes de tener el plan, o hacer commit antes de que termine el desarrollador).

## Reglas

- Cada subagente empieza sin tu contexto: en el `prompt` incluye la petición del usuario, el plan o la parte que le toca, rutas de archivo y cualquier decisión ya tomada.
- No repitas tú el trabajo de un subagente; si su resultado es insuficiente, vuelve a llamarle con instrucciones más precisas.
- Si un subagente devuelve un error o una duda que solo el usuario puede resolver, pregúntale al usuario.
- Al terminar, resume al usuario en pocas líneas qué hizo cada rol y el resultado (archivos, commit, comprobaciones).
