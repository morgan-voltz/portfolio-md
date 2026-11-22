using Portfolio.Application.Documents;
using static Portfolio.Application.Tests.TestData.TestDocuments;

namespace Portfolio.Application.Tests;

/// <summary>A document cannot change once built, nor can the catalog that holds it (ADR-026, point 1).</summary>
public sealed class DocumentTests
{
    [Fact]
    public void A_document_keeps_its_lists_when_the_caller_changes_its_own_afterwards()
    {
        // The client of the plugin (lot 3) will build documents from lists it owns. A document
        // that kept those lists would change, under the feet of its readers, whenever the client
        // changed them, and so would a catalog already being served.
        var projectTags = new List<Tag> { new("dotnet", ".NET") };
        var skills = new List<Skill> { new("e04-diagramme-de-classes-uml", "4.0.2/E04", "Modéliser en UML", Official: false) };
        var translations = new Dictionary<Language, string> { [Language.Fr] = "momocad" };
        var project = Project("momocad", Language.Fr, translations, tags: projectTags, skills: skills);
        var articleTags = new List<Tag> { new("rust", "Rust") };
        var article = Article("first-article", Language.Fr, Group("first-article"), tags: articleTags);

        projectTags.Clear();
        skills.Clear();
        translations[Language.Fr] = "changed";
        articleTags.Clear();

        Assert.Equal("dotnet", Assert.Single(project.Tags).Slug);
        Assert.Equal("e04-diagramme-de-classes-uml", Assert.Single(project.Skills).Slug);
        Assert.Equal("momocad", project.Translations[Language.Fr]);
        Assert.Equal("rust", Assert.Single(article.Tags).Slug);
    }
}
