namespace Portfolio.Application.Ports;

/// <summary>
/// The plugin could not be read this time: a network error, a timeout, a status
/// other than success, or an answer that is not JSON, such as the challenge
/// page of the host's firewall (ADR-026, point 2).
/// </summary>
public sealed record SourceUnavailable(string Reason);

/// <summary>What a port answers: what was asked, or why the plugin could not give it.</summary>
public union Fetch<T>(T, SourceUnavailable);
