using Portfolio.Application.Documents;

namespace Portfolio.Application.Tests;

/// <summary>The slugs of the project states, the plugin's own.</summary>
public sealed class ProjectStatusSlugsTests
{
    [Theory]
    [InlineData(ProjectStatus.EnCours, "en-cours")]
    [InlineData(ProjectStatus.Stable, "stable")]
    [InlineData(ProjectStatus.Archive, "archive")]
    [InlineData(ProjectStatus.Prototype, "prototype")]
    public void A_state_and_its_slug_lead_to_each_other(ProjectStatus status, string slug)
    {
        Assert.Equal(slug, status.Slug());
        Assert.Equal(status, ProjectStatusSlugs.FromSlug(slug));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("EnCours")]
    [InlineData("en_cours")]
    [InlineData("abandonne")]
    public void Anything_else_is_no_state(string? slug)
    {
        Assert.Null(ProjectStatusSlugs.FromSlug(slug));
    }
}
