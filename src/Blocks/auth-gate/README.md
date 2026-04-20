# Auth Gate Block

**Pattern demonstrated:** Inner block recursion with capability gating.

The auth-gate block wraps child blocks behind biometric authentication. Content only renders after the user successfully authenticates. It shows how to:

- Use `inner_blocks` to render child blocks at a specific position in the renderer tree
- Use `capability` action type with `biometrics` for authentication prompts
- Use `state` callback to write authentication result to a screen state slot (`_auth_gate_unlocked`)
- Use `visible_when` with state to conditionally reveal content after successful auth
- Compose a gate prompt (icon + heading + description + button) as a standalone section

This is the most structurally complex pattern — it combines capability invocation, state management, conditional rendering, and inner block recursion. Use it as a reference for any block that wraps children behind a condition.
