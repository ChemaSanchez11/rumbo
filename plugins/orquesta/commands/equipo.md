---
description: Ejecuta una tarea con todo el equipo (Sonnet planifica y documenta, Opus diseña y programa, Haiku sube a git)
argument-hint: <tarea a realizar>
---

Usa la skill `orquestar` para llevar a cabo esta tarea repartiéndola entre los subagentes `orquesta:analista`, `orquesta:disenador`, `orquesta:desarrollador` y `orquesta:operador`, lanzando en paralelo todo lo que no dependa entre sí:

$ARGUMENTS

Al final, pide al operador que haga commit y push de los cambios a la rama actual.
