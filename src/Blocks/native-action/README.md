# Native Action Block

**Pattern demonstrated:** Capability invocation with callback contract.

The native-action block triggers a NativePHP capability (camera, scanner, share, browser variants) and handles the result via a callback. It shows how to:

- Use `capability` action type to invoke a native device feature
- Wire the callback contract: `type` selects state or endpoint, `slot` names the state target, `url` names the endpoint target
- Use `visible_when` for fallback text shown when the capability is unavailable
- Bind action configuration to block attributes for per-instance customization

This is the most complex action pattern. Every callback variation (state for in-screen updates, endpoint for server-side persistence) is demonstrated here. See the `auth-gate` block for the capability-gating pattern (biometrics wrapping children).
