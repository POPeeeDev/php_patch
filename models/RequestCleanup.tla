-------------------------- MODULE RequestCleanup --------------------------
EXTENDS Naturals, FiniteSets
CONSTANTS Roots, Requests, Steps, DetachFirst, Checkpoint
ASSUME /\ Roots = 2 /\ Requests = 2 /\ Steps = 20
       /\ DetachFirst \in BOOLEAN /\ Checkpoint \in BOOLEAN
VARIABLES phase, published, held, released, soft, request, late, badRead, fuel
refs == 1..Roots
vars == <<phase, published, held, released, soft, request, late, badRead, fuel>>
Init == /\ phase = "running" /\ published \in SUBSET refs
        /\ held = {} /\ released = {} /\ soft = FALSE /\ request = 1
        /\ late = FALSE /\ badRead = FALSE /\ fuel = Steps
Tick(A) == /\ fuel > 0 /\ fuel' = fuel - 1 /\ A
SoftOOM == Tick(/\ phase = "running" /\ ~soft /\ soft' = TRUE
                /\ UNCHANGED <<phase, published, held, released, request, late, badRead>>)
\* Vendor safe-point completion is a refinement obligation, not established by this model.
SafePoint == Tick(/\ phase = "running" /\ phase' = "cleanup"
                  /\ UNCHANGED <<published, held, released, soft, request, late, badRead>>)
HardOOM == Tick(/\ phase \notin {"dead", "done"} /\ phase' = "dead"
                /\ UNCHANGED <<published, held, released, soft, request, late, badRead>>)
Detach == Tick(/\ phase = "cleanup" /\ held' = published
               /\ published' = IF DetachFirst THEN {} ELSE published
               /\ phase' = "held"
               /\ UNCHANGED <<released, soft, request, late, badRead>>)
Release(r) == Tick(/\ phase = "held" /\ r \in held
                   /\ held' = held \ {r} /\ released' = released \cup {r}
                   /\ UNCHANGED <<phase, published, soft, request, late, badRead>>)
Finish == Tick(/\ phase = "held" /\ held = {} /\ phase' = "checkpoint"
               /\ UNCHANGED <<published, held, released, soft, request, late, badRead>>)
\* Fault injection: a late producer republishes a released root.
LateRoot(r) == Tick(/\ phase = "checkpoint" /\ ~late /\ r \in released
                    /\ published' = published \cup {r} /\ late' = TRUE
                    /\ UNCHANGED <<phase, held, released, soft, request, badRead>>)
Reuse == Tick(/\ phase = "checkpoint"
              /\ phase' = IF Checkpoint /\ published # {} THEN "dead"
                           ELSE IF request = Requests THEN "done" ELSE "running"
              /\ badRead' = (badRead \/ (~Checkpoint /\ published \cap released # {}))
              /\ request' = IF phase' = "running" THEN request + 1 ELSE request
              /\ published' = IF phase' = "running" THEN refs ELSE published
              /\ released' = IF phase' = "running" THEN {} ELSE released
              /\ soft' = IF phase' = "running" THEN FALSE ELSE soft
              /\ late' = IF phase' = "running" THEN FALSE ELSE late
              /\ UNCHANGED held)
Next == SoftOOM \/ SafePoint \/ HardOOM \/ Detach \/ Finish \/ Reuse
        \/ (\E r \in refs: Release(r) \/ LateRoot(r))
TypeOK == /\ phase \in {"running", "cleanup", "held", "checkpoint", "done", "dead"}
          /\ published \subseteq refs /\ held \subseteq refs /\ released \subseteq refs
          /\ soft \in BOOLEAN /\ late \in BOOLEAN /\ badRead \in BOOLEAN
          /\ request \in 1..Requests /\ fuel \in 0..Steps
DetachBeforeRelease == phase = "held" => published \cap released = {}
NoStaleConsumer == ~badRead
CleanReuse == phase = "done" => published = {}
RankDescent == [][fuel' < fuel \/ (fuel' = fuel /\ Cardinality(held') < Cardinality(held))]_vars
Termination == <>(phase \in {"done", "dead"})
Spec == Init /\ [][Next]_vars /\ WF_vars(Next)
=============================================================================
